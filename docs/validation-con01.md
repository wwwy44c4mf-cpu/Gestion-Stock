# Validation CON-01 — 21 octobre 2026

Recette complète réussie : **338 tests PHP / 4 428 assertions** et **4 validations OpenAPI indépendantes**. Les **12 nouveaux tests / 109 assertions** couvrent les droits, filtres, totaux exacts, corrections, retours et parcours HTML. Les journaux de pannes injectées et de retours arrière refusés sont attendus par les tests existants.

- Utilisateur : uniquement ses consommations, y compris celles saisies pour lui ; être opérateur d’une consommation destinée à autrui ne la rend pas personnelle. Historique conservé après retrait d’affectation, sans lien ni accès au stock extérieur. Autre bénéficiaire refusé. Magasins désactivés exclus hors administrateur.
- Gestionnaire : seulement ses magasins actifs affectés ; administrateur : ensemble des magasins, y compris désactivés. Gestionnaire sans affectation : liste et totaux vides. Retrait d’affectation, désactivation de magasin et révocation de session pris en compte.
- Dates inclusives, journée du passage à l’heure d’été, bornes indépendantes et fuseau Pacific/Honolulu. Combinaison dates/article/catégorie exacte/magasin/bénéficiaire, tris date/quantité et identifiant stable pour des lignes de même date et quantité. Pagination avec conservation des filtres.
- Totaux calculés sur l’ensemble filtré, indépendamment de la page ; sorties et retours séparés. Somme de deux quantités maximales conservée exactement sous forme de chaîne, sans perte de précision JavaScript. Retours postérieurs à la période de consommation comptés comme retours liés, avec libellé explicite.
- Lignes annulées et corrigées conservées avec statut ; sorties remplacées et retours annulés exclus des totaux valides. Liens de remplacement limités au périmètre. Protections SQL existantes vérifiées : une correction ne peut pas changer bénéficiaire, magasin ou consommation d’origine d’un retour.
- Identités anonymisées lisibles, référence facultative et textes échappés. Options de personnes limitées aux consommations autorisées et sans adresses de courriel. Détail enrichi des retours liés avec opérateur, date, quantité et état.
- Paramètres inconnus, types imbriqués, dates impossibles et bornes inversées refusés. Accès anonyme refusé. Réponse confrontée au schéma publié ; parcours de création, retour et correction existants revérifiés par la recette complète.

## Vérification locale

Aucune migration ajoutée ; 16 migrations déjà installées. Avant/après : cinq mouvements, somme des quantités 12, un inventaire, zéro notification et un lot quotidien existant. Aucun article, compte, consommation ou retour fictif ajouté. La reconnexion du compte de développement ajoute son événement normal d’authentification ; les consultations n’ajoutent aucune écriture métier.

Contrôle visuel sur ordinateur et à 390 pixels : liste réelle de deux consommations, totaux quatre unités sorties et une rendue, détail du retour existant, combinaison des cinq filtres puis réinitialisation. La largeur de page reste de 390 pixels ; le tableau conserve son défilement horizontal propre. Le filtre du 20 octobre, pour le bénéficiaire sélectionné, renvoie une consommation de deux unités et aucun retour lié. Le compte de test reste réservé au développement.

Les essais d’écriture et cas limites utilisent des schémas PostgreSQL isolés, annulés après chaque test. L’envoi réel de courriels demeure désactivé. App, base, worker, scheduler et web sains.

Contrat : **61 opérations / 97 schémas**, dont **56 disponibles / 5 prévues**. Le schéma de lecture des consommations contient les libellés, statuts et totaux ; aucun contrat d’écriture de stock n’est changé.

Décision utilisateur : conserver les lignes annulées ou remplacées avec leur statut explicite. Les graphiques et statistiques appartiennent à STAT-01.

[Guide](historique-consommations.md) · [Issue #30](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/30).
