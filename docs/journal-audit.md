# Journal d’audit métier — ADM-01

`/audit` et `GET /api/v1/audit-logs` présentent les actions sensibles déjà enregistrées par les modules métier. Le journal est paginé, filtrable par dates inclusives dans le fuseau du compte, magasin, acteur visible et code d’action exact. Le tri par date est complété par l’identifiant pour stabiliser les pages. L’écran affiche les événements les plus récents en premier ; l’API conserve le défaut de tri du contrat commun.

## Accès et périmètre

- Administrateurs : événements globaux et événements de tous les magasins, y compris désactivés.
- Gestionnaires : uniquement les événements rattachés à leurs magasins actifs affectés. Les changements globaux de comptes, droits, catalogue et authentification restent réservés aux administrateurs. Un gestionnaire sans magasin obtient un journal vide.
- Utilisateurs : consultation refusée, même pour leurs propres opérations. Les historiques de consommations restent accessibles dans leur module.

Le magasin de référence est celui enregistré avec l’événement. Les opérations de transfert sont actuellement auditées sur leur magasin source ; la consultation ne réattribue pas cet événement au magasin destination et ne fabrique pas d’historique rétroactif.

Pour les événements visant un mouvement dont une partie est hors du périmètre courant, le gestionnaire voit une ligne locale avec acteur, cible et contexte masqués. Le filtre d’acteur et les options de personnes s’appliquent après ce masquage : ils ne permettent pas de retrouver un acteur caché. Les dates, actions et comptages restent limités aux événements du magasin autorisé.

Le contexte magasin commun est repris par défaut. Un `store_id` explicitement vide et la réinitialisation reviennent à l’ensemble du périmètre autorisé. Les options d’acteurs et d’actions proviennent exclusivement de ce périmètre et ne contiennent pas d’adresses de courriel. Les acteurs anonymisés sont libellés « Compte anonymisé » ; un acteur absent est présenté comme « Système ou acteur inconnu ».

## Informations visibles

Chaque événement présente son identifiant, sa date, son acteur quand il est visible, son magasin, son code d’action et sa cible. Le contexte public se déplie au clavier. Les identifiants de cibles ne donnent aucun accès supplémentaire et ne sont pas transformés en liens privilégiés.

Le contexte est une projection positive et typée, appliquée à la lecture des événements anciens comme nouveaux :

- Comptes, droits, catalogue et magasins : noms de champs modifiés, jamais les valeurs libres ni mots de passe.
- Inventaires : état, quantités et versions de comptage, identifiants des articles et ajustements connus.
- Stock : type, identifiants d’origine/consommation/inventaire, écarts signés et seuils avant/après. Zéro reste distinct de l’absence de seuil.
- Destinataires d’alertes : mode par défaut et nombre de sélections avant/après, sans coordonnées ni identifiants des destinataires.
- Informations et récapitulatifs : nombres de destinataires/éléments et date du lot quotidien.

Les champs inconnus, contenus de messages, commentaires, raisons libres, mots de passe, jetons, cookies, paramètres SMTP et empreintes de connexion sont omis. Une action non reconnue reste visible avec son attribution et sa date mais sans contexte public. Le JSON source n’est jamais affiché brut. Les libellés HTML sont échappés.

## Traçabilité et immutabilité

Les producteurs existants écrivent l’audit dans la transaction métier : administration des comptes et affectations, magasins, catalogue et propositions, opérations/corrections de stock, inventaires, seuils et préférences de notification. Les événements d’authentification et tâches système gardent leur attribution disponible. Une erreur de transaction ne laisse pas une fausse action métier validée. La lecture n’ajoute aucun événement, mouvement ou notification.

Aucun formulaire ni endpoint de modification du journal n’est créé. Les déclencheurs PostgreSQL existants refusent les mises à jour et suppressions ordinaires. La procédure d’anonymisation relève de RGPD-01 et utilisera son circuit dédié ; ADM-01 ne modifie pas la rétention convenue d’un an. Les administrateurs de base disposant de privilèges de maintenance restent responsables de ce circuit ; la protection ne prétend pas résister à un administrateur système qui désactive les contraintes.

Aucune migration ajoutée. Les filtres invalides, inconnus ou imbriqués sont refusés avec 422 ; identifiant de magasin hors périmètre avec 404 ; utilisateur simple avec 403 et requête anonyme API avec 401.

[Issue #33](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/33).
