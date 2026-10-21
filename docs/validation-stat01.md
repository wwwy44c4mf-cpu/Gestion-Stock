# Validation STAT-01 — 21 octobre 2026

Recette complète réussie : **350 tests PHP / 4 548 assertions** et **4 validations OpenAPI indépendantes**. Les 12 nouveaux tests de statistiques comptent 120 assertions. Les erreurs de pannes injectées dans les journaux sont attendues par les tests existants.

- Périmètres administrateur, gestionnaire et utilisateur vérifiés pour chaque agrégat. Une consommation personnelle reste consultable après retrait d’affectation, sans accès supplémentaire au stock. Les magasins désactivés sont réservés aux administrateurs. Retrait des droits, anonymisation et absence de courriels dans les réponses vérifiés.
- Dates inclusives dans le fuseau du compte, changement d’heure, Pacific/Honolulu, semaines commençant le lundi et frontière d’année ISO. Retours comptés à leur date réelle, même quand la consommation d’origine est hors période ; bénéficiaire de la consommation conservé. Quantités sorties et rendues séparées.
- Corrections et annulations exclues ; remplacement compté à sa propre date. Entrées, transferts, ajustements et inventaires exclus. Classements distincts par quantité et par nombre de sorties, départages déterministes, limite appliquée uniquement aux classements.
- Filtres combinés date/magasin/catégorie exacte ; répartitions par bénéficiaire, magasin et catégorie, y compris sans catégorie et groupes comportant seulement des retours. Calendrier complété par des zéros. Bornes ouvertes, périmètre vide et absence d’activité vérifiés.
- Somme exacte de 18 014 398 509 481 982 unités conservée comme chaîne dans chaque agrégat. Ratios graphiques internes séparés du contrat public. Réponses confrontées au schéma publié.
- Paramètres invalides, inconnus ou imbriqués et accès anonymes refusés. Plus de 10 000 périodes ou 1 000 groupes : erreur explicite 422, sans troncature silencieuse. Textes échappés et navigation vérifiés.

## Recette visuelle et données locales

Écran réel contrôlé sur ordinateur et à 390 pixels : filtres combinés, réinitialisation, regroupements jour/semaine/mois et tableau exact dépliable. Octobre affiche quatre unités sorties, deux consommations et une unité rendue ; le 20 octobre affiche deux unités sorties et une rendue. Les semaines du 12 et du 19 octobre comportent chacune deux unités sorties, avec le retour dans la seconde. Aucun débordement horizontal de page sur téléphone ; les tableaux disposent de leur défilement local.

La feuille de style porte désormais une version calculée à partir de son contenu : une ancienne copie en cache ne masque plus les nouveaux composants. Chargement et mise en forme vérifiés dans le navigateur.

Aucune migration ajoutée. Après recette : 16 migrations, cinq mouvements, somme des stocks 12, un inventaire, zéro notification et un lot quotidien déjà existant. Les deux reconnexions de recette expliquent le passage de 56 à 58 événements d’audit. Aucun compte, mouvement ou article de démonstration ajouté ; les tests d’écriture utilisent des schémas isolés annulés après test. Envoi réel de courriels désactivé et services applicatifs sains.

Contrat : **61 opérations / 97 schémas**, dont **57 disponibles / 4 prévues**. Le tableau de bord relève de STAT-02.

Décision utilisateur : comptabiliser les retours à leur date réelle, pour suivre l’activité de la période.

[Guide](statistiques-consommation.md) · [Issue #31](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/31).

Livraison pour revue : [PR #80](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/80), basée sur main après intégration de CON-01.
