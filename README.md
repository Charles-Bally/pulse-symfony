# pulse-symfony

Ton app Symfony surveillée par [Pulse](https://pulse-app.si), sur ton iPhone et ton Mac. Tu ajoutes le bundle, Pulse trouve tout seul tes connexions Doctrine, ton Redis, tes transports Messenger et ton Scheduler, et te prévient dès que quelque chose cloche.

```
  ton app Symfony                                          ton iPhone, ton Mac
 ┌──────────────────────────┐        HTTPS        ┌──────────┐       ┌──────────────┐
 │ bin/console pulse:work   │ ──────────────────▶ │  Pulse   │ ────▶ │  app Pulse   │
 │  ├ Doctrine    SELECT 1  │   état, tâches,     │  (API)   │ push  │  alertes,    │
 │  ├ Redis       PING      │   journaux          │          │ ◀──── │  journaux,   │
 │  ├ Messenger   en attente│   (chiffrés si tu   │          │  SSE  │  en direct   │
 │  └ Scheduler   passages  │    le veux)         └──────────┘       └──────────────┘
 └──────────────────────────┘
        ▲ var/pulse/ : journaux et passages des tâches, écrits par tes autres processus
```

Une requête PHP est courte : le rapport ne part donc jamais d'une requête web. Un petit worker, `bin/console pulse:work`, tourne à côté de ton app (comme `messenger:consume`) et parle à Pulse à sa place.

## En deux minutes

```bash
composer require pulse-app/pulse-symfony
```

Ajoute le bundle s'il n'y est pas déjà :

```php
// config/bundles.php
return [
    // …
    Pulse\Symfony\PulseBundle::class => ['all' => true],
];
```

Puis la clé que l'app Pulse t'affiche quand tu ajoutes une app, et lance le worker :

```bash
# .env.local
PULSE_KEY=pk_live_…
```

```bash
bin/console pulse:work
```

Le premier signal arrive dans Pulse deux secondes plus tard. Pour un essai ponctuel, `bin/console pulse:report` envoie un seul rapport et affiche ce qui a été mesuré.

Aucune configuration n'est obligatoire : le bundle lit les variables `PULSE_*`. Les mêmes réglages existent en YAML et passent avant les variables :

```yaml
# config/packages/pulse.yaml
pulse:
    send: checks,crons,logs
    exclude: 'File failed'
    log_level: WARN
    http_probes:
        'Route /health': 'https://nimbus.fr/health'
```

## Lancer le worker

Le worker suit le rythme dicté par l'API, s'arrête proprement sur `SIGTERM` et `SIGINT` (avec l'extension `pcntl`) et ne fait jamais tomber ton app.

### Supervisor

```ini
[program:pulse]
command=php /var/www/nimbus/bin/console pulse:work
autostart=true
autorestart=true
stopwaitsecs=15
user=www-data
```

### systemd

```ini
[Unit]
Description=Pulse pour Nimbus
After=network-online.target

[Service]
WorkingDirectory=/var/www/nimbus
ExecStart=/usr/bin/php bin/console pulse:work
Restart=always
User=www-data

[Install]
WantedBy=multi-user.target
```

### Docker Compose

```yaml
services:
  pulse:
    image: nimbus-app
    command: php bin/console pulse:work
    env_file: .env.local
    restart: unless-stopped
```

Le worker, `messenger:consume` et tes requêtes partagent le dossier `var/pulse/` : sur plusieurs conteneurs, monte `var/` en volume commun, ou au moins ce dossier.

## Ce que Pulse surveille

| Dépendance trouvée | Ce que Pulse fait | Nom affiché |
|---|---|---|
| connexions Doctrine DBAL | `SELECT 1` (requête neutre de la plateforme) | PostgreSQL, MySQL, MariaDB, SQLite, SQL Server, Oracle |
| services `\Redis`, `Predis\ClientInterface`, `Relay\Relay`, pools de cache sur Redis | `PING` | Redis |
| transports Messenger qui savent compter (Doctrine, Redis, SQS…) | lit le nombre de messages en attente | File async, File failed… |
| Scheduler (`#[AsSchedule]`, `#[AsCronTask]`, `#[AsPeriodicTask]`) | expression, dernier et prochain passage, réussite | le message de la tâche |

Chaque contrôle a 5 secondes pour répondre et ne fait que lire. Une dépendance en échec, une tâche qui a planté ou qui a sauté son passage de plus de 5 minutes, et l'app passe en « dégradée » dans Pulse avec une notification qui dit quoi.

Le nom d'une tâche est celui de son message (`RelanceAbonnements`), de la commande pour un `RunCommandMessage` (`app:relance`) ou du service pour `#[AsCronTask]`. Les passages sont relevés par `messenger:consume scheduler_default` lui-même : un handler qui lève compte comme un échec, avec son message.

Pour suivre un travail qui ne passe pas par le Scheduler (un cron système, une commande), enveloppe-le avec le service `Pulse\Pulse` :

```php
use Pulse\Pulse;

public function __construct(private Pulse $pulse) {}

public function __invoke(): void
{
    $this->pulse->track('relance-abonnements', fn () => $this->abonnements->relancer());
}
```

En cas d'erreur, l'échec est noté puis l'erreur te revient telle quelle.

## Choisir ce qui part

Tu décides de ce que ton app raconte à Pulse. Le signal de vie part toujours, le reste se règle :

```bash
# Seulement l'état des dépendances et les journaux, pas les tâches ni les versions
PULSE_SEND=checks,logs

# Ignorer certaines dépendances ou certaines tâches (* sert de joker)
PULSE_EXCLUDE=File failed,Nettoyage*

# Ou au contraire, ne suivre que celles-ci
PULSE_INCLUDE=PostgreSQL,Redis,RelanceAbonnements

# Journaux : seulement les avertissements et les erreurs
PULSE_LOG_LEVEL=WARN
```

## Journaux et mode direct (Pro et Team)

Avec MonologBundle, le bundle déclare un handler `pulse` qui reçoit tous les canaux sauf `event`, sans rien changer à tes handlers : il passe après eux (priorité basse) et ne garde jamais un message pour lui. Le niveau et le canal sont repris, les secrets masqués avant de partir (`token=***`, `Bearer ***`, identifiants dans les URL de connexion).

```
app.ERROR: Paiement refusé token=abc123
                                   │
                                   ▼  dans l'app Pulse
ERROR  app  Paiement refusé token=***
```

Le handler écrit chaque ligne nettoyée dans `var/pulse/logs.ndjson` (1000 lignes au plus) et le worker vide ce fichier toutes les 10 secondes. Tant que Pulse n'a pas confirmé que ton offre comprend les journaux, rien n'est écrit : sans journaux dans l'offre, ton app ne paie aucun surcoût. Si le worker s'arrête, la recopie se coupe d'elle-même quelques minutes plus tard.

Quand tu ouvres l'app dans Pulse, le serveur prévient le worker qu'on le regarde : l'état part toutes les 5 secondes et les journaux chaque seconde, puis tout revient au calme quand tu quittes l'écran. Les journaux sont gardés 3 jours en Pro et 7 jours en Team.

## Mode sécurisé : Pulse ne peut rien lire

C'est l'option pour qui ne veut confier ses données à personne, nous compris. Ton iPhone ou ton Mac fabrique une paire de clés. La clé publique va dans ton `.env.local`, la clé privée ne quitte jamais tes appareils.

```bash
PULSE_PUBLIC_KEY=pulse_pub_…   # copiée depuis l'app, Réglages de l'app > Mode sécurisé
```

```
 ton app ──── rapport chiffré (HPKE, X25519 + AES-256-GCM) ────▶ Pulse ──▶ ton iPhone
                                                                 │          │
                                                    garde un paquet         ouvre avec
                                                    qu'il ne sait pas       la clé privée
                                                    ouvrir                  du trousseau
```

Un serveur qui ne lit rien ne peut pas alerter. C'est pour ça qu'un petit résumé reste en clair par défaut : « tout va bien » ou « 2 soucis », sans nom ni message. Tu peux en laisser plus, ou rien du tout :

```bash
PULSE_CLEAR=status            # par défaut : un booléen et un nombre
PULSE_CLEAR=status,checks     # Pulse peut dire « Redis ne répond plus »
PULSE_CLEAR=none              # silence complet : Pulse ne sait plus que si l'app est en vie
```

Le mode sécurisé demande les extensions `sodium` et `openssl` de PHP (présentes dans la plupart des installations). Sans elles, le worker refuse de démarrer plutôt que d'envoyer en clair. Une fois le mode actif, Pulse refuse tout rapport en clair : un `.env` oublié ne fuit pas.

## Sécurité, en clair

- **Ton app d'abord.** Le bundle ne lève jamais d'erreur vers ton app (sauf `track`, qui te rend l'erreur de ta tâche). Pulse injoignable : un seul avertissement, le worker continue. Clé refusée : les envois s'arrêtent.
- **Rien n'est écrit chez toi.** Les contrôles ne font que lire (`SELECT 1`, `PING`, nombre de messages), avec 5 secondes au plus. Le bundle n'écrit que dans `var/pulse/`. Les transports AMQP ne sont pas comptés : les interroger déclare la file.
- **Rien de superflu ne part.** Ni requête, ni donnée métier, ni variable d'environnement. Les messages d'erreur sont nettoyés et coupés à 300 caractères.
- **La clé ne voyage qu'en HTTPS**, dans un en-tête, jamais dans une URL. Elle ne sert qu'à écrire l'état de cette app : elle n'ouvre pas ton compte.
- **Une seule dépendance** en dehors de Symfony : `pulse-app/pulse-php`, qui n'utilise que des extensions courantes de PHP.

## Variables

| Variable | Défaut | Rôle |
|---|---|---|
| `PULSE_KEY` | (obligatoire) | clé de l'app : `pk_live_…` en production, `pk_test_…` en staging |
| `PULSE_SEND` | `checks,crons,logs,versions` | ce qui part en plus du signal de vie |
| `PULSE_INCLUDE` | (tout) | seuls ces contrôles et tâches, noms séparés par des virgules, `*` permis |
| `PULSE_EXCLUDE` | (rien) | contrôles et tâches ignorés |
| `PULSE_LOG_LEVEL` | `DEBUG` | niveau minimal des journaux envoyés |
| `PULSE_LOGS` | `true` | `false` coupe les journaux |
| `PULSE_PUBLIC_KEY` | (aucune) | active le mode sécurisé |
| `PULSE_CLEAR` | `status` | en mode sécurisé, ce qui reste lisible par Pulse |
| `PULSE_ENDPOINT` | `https://api.pulse-app.si` | ton Pulse Relay en mode VPS perso (Team), par exemple `https://relais.nimbus.fr` |
| `PULSE_RELAY_FINGERPRINT` | (aucune) | empreinte du certificat d'un relais sans domaine : rien ne part si elle ne correspond pas |
| `PULSE_APP_VERSION` | (aucune) | version affichée dans Pulse |
| `PULSE_DISABLED` | `false` | `true` coupe tout (tests, CI) |

## Si rien n'arrive

| Message | Ce qu'il faut faire |
|---|---|
| `PULSE_KEY absente` | la variable n'est pas chargée : vérifie `.env.local`, ou les variables du service |
| `Clé Pulse refusée` | la clé a été remplacée dans l'app : recopie la nouvelle |
| `Mode sécurisé actif pour cette app` | ajoute `PULSE_PUBLIC_KEY` |
| `PULSE_PUBLIC_KEY ne correspond plus` | la clé a changé sur ton téléphone : recopie la clé publique |
| `Pulse injoignable` | réseau ou pare-feu : le worker continue, les envois reprennent seuls |
| aucune tâche planifiée | lance `messenger:consume scheduler_default` avec la même `PULSE_KEY` que le worker |
| aucun journal | MonologBundle doit être installé ; web et consommateurs doivent voir la même `PULSE_KEY` et le même `var/pulse/` |

Le rythme des rapports suit celui de l'app dans Pulse (30 secondes en Pro, 5 minutes en Gratuit).

## Licence

MIT
