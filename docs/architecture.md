# Architecture du MVP

Symfony/Twig rend l’interface française ; Stimulus apporte les interactions locales sans frontend séparé. L’API utilise les mêmes règles métier. DBAL accède à PostgreSQL ; les migrations sont explicites. [Modèle de données et relations](modele-donnees.md) · [Contrat OpenAPI](openapi.json).

```mermaid
flowchart LR
    B[Navigateur] -->|HTTPS 443| P[Caddy intégré]
    P -->|HTTP privé| W[Nginx]
    W -->|FastCGI privé| A[Symfony PHP-FPM]
    A -->|rôle applicatif| D[(PostgreSQL 18)]
    S[Scheduler] --> D
    Q[Worker Messenger] --> D
    Q -->|SMTP configuré| M[Service de courriel]
    K[Sauvegarde quotidienne] -->|lecture| D
    K --> E[Archives chiffrées 90 jours]
    E -. copie à configurer .-> X[Destination hors serveur]
    O[Opérateur de maintenance] -->|traitement privilégié hors HTTP| D
```

Le proxy HTTPS est ajouté par les overlays de production ; l’installation locale publie seulement Nginx sur loopback. Les réseaux isolent accès web et PostgreSQL. App, worker et scheduler utilisent le rôle applicatif, sans secret administrateur de base. Les services ont leurs sondes, espaces temporaires, limites de privilèges et journaux rotatifs. [Configuration détaillée](deploiement-production.md).

```mermaid
sequenceDiagram
    participant U as Client authentifié
    participant A as Service métier
    participant D as PostgreSQL
    participant W as Worker
    participant M as SMTP
    U->>A: Mouvement + CSRF + clé de rejeu
    A->>D: Transaction : droits, gel, verrous, stock
    D-->>A: Solde + journal + audit + événement validés ensemble
    A-->>U: Résultat confirmé
    W->>D: Traiter les événements et notifications durables
    W->>M: Livrer si SMTP activé
    M-->>W: Résultat de livraison
    W->>D: État et reprise éventuelle
```

Une panne SMTP ne défait pas le stock validé. Les transferts verrouillent les deux magasins dans un ordre stable ; une clé rejouée ne refait pas le débit. Les corrections conservent l’original. Un inventaire gèle les mutations jusqu’à validation ou annulation. [Moteur transactionnel](moteur-stock.md).

La base, les files et les volumes restent sur **un seul serveur**. Le propriétaire accepte le risque résiduel de perte jusqu’à environ 24 h depuis la dernière sauvegarde quotidienne réussie ; la cible de reprise est un jour ouvré. Une copie hors serveur est nécessaire avant production. Toute restauration déclenche un verrou d’accès jusqu’au retraitement de conservation. Ni la réplication synchrone ni un second hébergement ne sont prévus au MVP.
