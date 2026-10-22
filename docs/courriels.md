# Courriels persistants — MAIL-01

Le socle utilise Symfony Mailer pour SMTP et Messenger avec PostgreSQL. Les notifications métier sont enregistrées dans `notification_events` au sein de la transaction métier. Une opération qui échoue ne conserve pas de notification ; une panne SMTP n’annule pas un mouvement validé. Le cycle des alertes est raccordé dans ALT-02 et ses préférences dans ALT-03. Les messages administratifs et le récapitulatif sont livrés par MAIL-02 et MAIL-03.

## Configuration

L’envoi est désactivé par défaut (`MAIL_DELIVERY_ENABLED=0`). Les événements restent en attente : ils ne sont ni envoyés ni marqués comme livrés. Aucun SMTP réel n’est utilisé par les tests. Exécuter la préparation locale habituelle pour créer le fichier manquant sans écraser les secrets existants.

Configurer sur le serveur, sans publier les valeurs dans Git :

- `.secrets/mailer_dsn` : DSN SMTP, par exemple `smtp://utilisateur:mot-de-passe-encode@smtp.example.net:587?require_tls=true`. Utiliser `smtps://` pour TLS implicite (habituellement port 465). Encoder les caractères réservés des identifiants ; conserver la vérification des certificats.
- `MAIL_FROM_ADDRESS` : adresse d’expéditeur autorisée par votre service SMTP.
- `APP_PUBLIC_URL` : adresse publique HTTPS, sans identifiants, recherche ou fragment. HTTP n’est accepté qu’en développement et test.
- `MAIL_DELIVERY_ENABLED=1` après configuration, puis recréer app, worker et scheduler.

Le secret est monté via Docker ; son contenu est lu uniquement au moment de l’envoi. Seuls les transports SMTP sont acceptés : un transport qui jette silencieusement les courriels est refusé. Le délai réseau est limité à 10 secondes. Les diagnostics du fournisseur, les adresses et le contenu ne sont pas journalisés. Les arguments des exceptions PHP sont masqués.

## File et reprises

Le scheduler recherche les événements en attente toutes les dix secondes, par lots de 100. Il écrit le message Messenger et le statut `queued` dans une même transaction PostgreSQL. Le message sérialisé ne contient que l’identifiant de notification. Deux répartiteurs utilisent `SKIP LOCKED` pour se partager le travail.

Le worker consomme `notifications` puis `async`. Un envoi utilise un verrou sur la notification, sans verrouiller les soldes de stock. L’adresse et l’activité du destinataire sont relues ; pour une alerte de magasin, son rôle et son affectation sont revérifiés. Les comptes de développement sont exclus en production. Un destinataire indisponible ou un modèle invalide produit un échec définitif, sans envoi.

Une panne SMTP autorise trois reprises après l’essai initial, avec délais de 60, 120 et 240 secondes. Après quatre échecs, la notification reste `failed` et le message rejoint le transport Messenger `failed`. Les tentatives sont conservées dans `notification_delivery_attempts`, avec date, numéro et code technique fixe ; aucune exception SMTP brute n’y est stockée. Les événements déjà `sent` ne sont pas réexpédiés lors d’une redélivrance.

Après correction de la configuration, un opérateur peut autoriser un nouveau cycle borné :

```sh
docker compose exec app php bin/console app:notifications:retry IDENTIFIANT_NOTIFICATION
docker compose exec app php bin/console app:notifications:dispatch
```

Cette reprise conserve l’historique et ajoute un audit. Elle refuse un événement envoyé. Le reliquat de l’ancien message dans le transport `failed` peut être supprimé par `messenger:failed:remove IDENTIFIANT_MESSAGE` après vérification ; il ne faut pas confondre identifiant de message Messenger et identifiant de notification. `messenger:failed:show` et `messenger:stats` permettent l’inspection technique, avec les accès opérateur habituels.

Le couple clé d’événement/destinataire est fixé par le producteur ; toute réutilisation d’une clé pour un contenu différent est refusée. Le `Message-ID` SMTP est stable. Un serveur SMTP ne permet pas de garantir un envoi exactement une fois : une panne après acceptation du courriel mais avant confirmation locale peut conduire à une répétition. Les verrous, l’état envoyé et le Message-ID stable réduisent ce risque ; aucun effacement automatique d’historique ne le masque.

## Modèles et intégration

Les versions HTML et texte couvrent stock bas, stock critique, information, réinitialisation de mot de passe et synthèse. Elles affichent article, magasin, quantité, seuil, date Europe/Paris et lien applicatif selon le type. Le HTML est échappé et les liens utilisent l’adresse applicative configurée. Les données sont validées avant insertion et de nouveau avant rendu. La composition d’audience et le déclenchement métier appartiennent aux lots producteurs ; aucune route de démonstration d’envoi n’est exposée.

`NotificationOutbox::enqueue()` exige une transaction active sur la connexion métier. Le producteur doit y insérer l’événement, avec une clé stable propre au destinataire, avant le commit. Les modèles de réinitialisation ne rendent pas à eux seuls une procédure de réinitialisation disponible.

Sources techniques : [Symfony Mailer](https://symfony.com/doc/current/mailer.html) et [Symfony Messenger](https://symfony.com/doc/current/messenger.html).

Le producteur de [récapitulatif quotidien MAIL-03](recapitulatif-quotidien.md) est actif à 08 h Europe/Paris avec rattrapage du jour. Le worker retire les alertes résolues ou devenues inaccessibles avant la livraison ; les lots des jours précédents ne sont pas envoyés.
