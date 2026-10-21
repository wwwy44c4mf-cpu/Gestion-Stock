# Validation STAT-02 — 21 octobre 2026

Recette complète réussie : **359 tests PHP / 4 669 assertions** et **4 validations OpenAPI indépendantes**. Les neuf nouveaux scénarios du tableau de bord comptent 118 assertions et vérifient les droits, les agrégats et les destinations des liens. Les erreurs de pannes injectées dans les journaux sont attendues par les tests existants.

## Périmètre vérifié

- Articles actifs du catalogue commun, catégorie exacte, nombre de stocks en rupture et sous seuil concordant avec les listes. Article sans solde affiché à zéro ; article désactivé conservé dans les stocks ; seuil absent exclu des sous-seuils. Comptage par article et magasin, choix par défaut annoncé dans la conversation.
- Gestionnaire limité aux magasins actifs affectés ; administrateur avec accès aux magasins désactivés. Gestionnaire sans affectation : aucun stock ni mouvement, catalogue commun toujours lisible. Retrait d’affectation pris en compte et identifiants de magasins extérieurs refusés.
- Mouvements identiques au journal : chaque transfert est compté une fois, y compris transfert partiellement visible, avec les mêmes droits. Annulations et remplacements inclus dans le nombre d’événements ; consommations annulées exclues des volumes.
- Retours à leur date réelle, séparés des consommations. Concordance avec les statistiques, y compris pour un retour dont la consommation d’origine est hors période. Somme exacte de 18 014 398 509 481 982 unités conservée sous forme de chaîne. Bornes inclusives, journée de changement d’heure, fuseau Pacific/Honolulu et longue période sans matérialisation d’un calendrier.
- Alertes actives par gravité et catégorie concordant avec les listes ; dates de période sans effet sur les stocks et alertes actuels. Filtres de catégorie ajoutés aux listes de mouvements et alertes pour préserver les destinations des indicateurs.
- Utilisateur : vue personnelle seule, sans indicateurs de stock, alertes ou journal général ; consommations d’un autre bénéficiaire exclues même si saisies par lui. Accès personnel après retrait d’affectation, sans accès au stock extérieur ; magasin désactivé exclu. API de gestion refusée avec 403.
- Liens de toutes les cartes ouverts avec succès et filtres combinés conservés. Contexte magasin appliqué par défaut ; filtre explicite vide et réinitialisation neutralisent le contexte. Libellés échappés, paramètres inconnus ou imbriqués et dates invalides refusés. Accès anonyme redirigé vers la connexion pour HTML et refusé en API.

Les anciens parcours de connexion et de choix de magasin sont aussi couverts par la recette complète. L’accueil valide désormais ses paramètres : l’ancien test de rôle forgé vérifie le refus 422, puis les liens issus du rôle authentifié. Le sélecteur de magasins lit uniquement ses options autorisées, sans charger le détail des historiques.

## Vérification locale

Écran contrôlé sur ordinateur et à 390 pixels, sans débordement horizontal de page. Les cartes restent lisibles et les liens accessibles au clavier. Le mois d’octobre affiche un article actif, aucune rupture ni seuil bas atteint, cinq mouvements, quatre unités consommées et une rendue. Pour le 20 octobre avec magasin et catégorie sélectionnés : deux mouvements, deux unités consommées et une rendue ; le lien du journal confirme les deux lignes attendues.

Aucune migration ajoutée. Avant/après : 16 migrations, cinq mouvements, somme des stocks 12, un inventaire, zéro notification et un lot quotidien existant. Les trois reconnexions après reconstruction expliquent le passage de 58 à 61 événements d’audit (40 à 43 connexions réussies). Aucun mouvement, compte ou article de démonstration ajouté. Envoi réel de courriels désactivé et services applicatifs sains. Les scénarios d’écriture utilisent des schémas PostgreSQL isolés, annulés après test.

Contrat : **61 opérations / 97 schémas**, dont **58 disponibles / 3 prévues**. Aucun contrat d’écriture de stock n’est modifié.

[Guide](tableau-de-bord.md) · [Issue #32](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/32).
