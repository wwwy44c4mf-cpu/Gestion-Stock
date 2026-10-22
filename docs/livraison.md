# Fiche de livraison du MVP

Cette fiche prépare la livraison ; elle n’atteste pas d’une installation en production. Le MVP est intégré à main et sa recette finale est réussie : voir le [bilan de finalisation](finalisation-mvp.md). Aucun tag de version stable ni déploiement n’est créé par DOC-01. La variante [Portainer / Home Assistant](installation-portainer.md) fait l’objet du lot complémentaire OPS-04.

## Livrables

| Livrable du CDC §12 | Emplacement |
| --- | --- |
| Code et historique | Dépôt GitHub, PR liées dans [suivi](../suivi.md) |
| Régime de licence | [LICENSE](../LICENSE), propriétaire, tous droits réservés ; dépôt public |
| Installation et dépannage | [README](../README.md) |
| Docker et configuration | `Dockerfile`, `compose*.yaml`, `.env.example`, `.env.production.example`, `bin/` |
| Migrations et données fictives | `migrations/`, commande `app:dev:load-demo`, [démo](demonstration.md) |
| API, modèle et architecture | [OpenAPI](openapi.json), [API](api.md), [modèle](modele-donnees.md), [architecture](architecture.md) |
| Guides et permissions | [Utilisateur](guide-utilisateur.md), [administrateur](guide-administrateur.md), [matrice](controle-acces.md) |
| Sauvegarde et restauration | [Procédure](sauvegardes-restauration.md) |
| Maintenance, mise à jour et retour arrière | [Déploiement](deploiement-production.md), [diagnostic](supervision-diagnostic.md), [conservation](conservation-donnees.md) |
| Tests et rapport | [Recette QA](recette-qa01.md), [rapport QA](validation-qa01.md), [installation DOC-01](validation-doc01.md) |
| Sécurité, versions et contribution | [SECURITY](../SECURITY.md), [CHANGELOG](../CHANGELOG.md), [CONTRIBUTING](../CONTRIBUTING.md) |

## Réinitialisation du mot de passe

AUTH-04 fournit désormais demande, courriel temporaire et confirmation avec révocation des sessions ; [guide](reinitialisation-mot-de-passe.md). L’intégration et la recette du code sont terminées. Le parcours doit encore être testé sur des boîtes de réception autorisées après configuration du SMTP réel.

## Décisions et configuration avant ouverture

| Élément | État |
| --- | --- |
| Licence | Utilisation privée, tous droits réservés ; dépôt GitHub actuellement public, sa visibilité n’a pas été modifiée |
| Exploitant serveur/sauvegardes/incidents | Propriétaire du projet (confirmation : « Moi-même », 22 octobre 2026) |
| Responsable fonctionnel et habilitations | À désigner par l’organisation |
| Responsable du traitement et validation des durées | À désigner/valider par l’organisation |
| Serveur, ressources, domaine et DNS | À renseigner |
| HTTPS | Caddy intégré retenu ; certificat réel à valider sur le domaine |
| SMTP | Service disponible ; secret, expéditeur et essais contrôlés à configurer |
| Sauvegarde hors serveur et clé de reprise séparée | Destination non choisie ; transfert et restauration à tester |
| Supervision extérieure et canal privé d’incident | À choisir avec l’exploitant |
| Sauvegardes / reprise | Quotidiennes, 90 jours ; risque résiduel accepté ; cible un jour ouvré |

Ne pas remplir ces cases avec des valeurs inventées. Le propriétaire valide les personnes et les accès. La recette sur données fictives n’a envoyé aucun message réel.

## Préparer la version

1. Relire et intégrer les PR dans l’ordre de leurs dépendances, dont RGPD-01 puis QA-01 puis DOC-01. Vérifier les CI du commit final ; une CI verte sur un ancêtre n’atteste pas automatiquement de la fusion finale.
2. Figer le commit et un `RELEASE_ID` unique ; reporter version/commit/date/opérateur dans le dossier de livraison. Ne pas réutiliser un tag d’image pour un contenu différent.
3. Exécuter `sh bin/test-qa full` et `sh bin/test-installation` sur ce commit, conserver rapports et liens CI privés. Refaire l’audit Composer et images, sans masquer d’alerte ; consigner les décisions de sécurité.
4. Faire parcourir la [démonstration](demonstration.md) au responsable fonctionnel ; faire valider catalogue, droits, seuils, restitutions et procédures.
5. Compléter la configuration serveur et les responsabilités ci-dessus avant toute ouverture réelle. Appliquer la [première installation HTTPS](deploiement-production.md) ; créer un vrai administrateur, sans données de démo.

## Vérifier le serveur avant ouverture

- Vérifier HTTPS, domaine de confiance exact, proxy, debug désactivé, aucun port base/PHP publié et aucun secret versionné. Contrôler les sessions et rôles avec des comptes autorisés.
- Activer les sauvegardes et leur copie extérieure, mettre la clé de déchiffrement séparément à l’abri. Restaurer une archive sur une cible vierge ; vérifier intégrité puis retraitement des échéances avant de rouvrir. Mesurer la reprise face à la cible d’un jour ouvré.
- Installer et vérifier le timer de conservation sur le serveur, surveiller ses résultats. Configurer une supervision extérieure, les seuils de disque et le destinataire des incidents.
- Configurer SMTP et tester sur des destinataires consentants ; vérifier livraison, reprise et récapitulatif de 08 h. Une simple file non vide n’est pas une preuve d’envoi.
- Noter l’accord du propriétaire et du responsable fonctionnel, le commit ouvert, l’adresse du service et les preuves. Créer ensuite le tag/version de livraison et compléter le changelog avec la date réelle.

## Maintenance et reprise

Au quotidien : diagnostic, dernier succès de sauvegarde/copie, espace disque, files et échecs d’envoi, dernier traitement de conservation. Une mise à jour exige sauvegarde préalable et images identifiées ; suivre [OPS-02](deploiement-production.md), y compris ses conditions de reprise. Une migration peut interdire son retour arrière pour protéger l’historique : ne pas forcer une suppression de tables ou de volumes. Pour un incident, utiliser [OPS-03](supervision-diagnostic.md) et [OPS-01](sauvegardes-restauration.md). La restauration ne lève pas elle-même le blocage RGPD.
