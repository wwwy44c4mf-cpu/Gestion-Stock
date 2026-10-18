# Validation STK-02 — Moteur transactionnel de stock

Recette Docker du 18 octobre 2026 : **146 tests PHP / 2 173 assertions et quatre validations indépendantes OpenAPI réussis**. Suite complète : `docker compose run --rm test`, sortie 0, durée PHPUnit 52,164 s.

## Preuves des critères d’acceptation

| Exigence | Contrôle effectué |
|---|---|
| REC-05 : sortie supérieure au disponible | Stock 11, sortie 20 refusée 409 ; instantané des soldes, mouvements, événements et audits identique avant/après. Une première sortie sans solde ne laisse aucune ligne créée. |
| REC-06 : dernière unité concurrente | Deux processus PHP avec connexions PostgreSQL distinctes, libérés par une barrière commune : une consommation réussit, l’autre reçoit `insufficient_stock`, solde final zéro, une seule consommation au journal. |
| REC-14 : même requête répétée | Deux processus avec la même clé reçoivent exactement le même mouvement ; un seul débit/crédit, événement et audit. Rejeu également testé après écritures ultérieures, archivage d’article, démarrage d’inventaire et désactivation du bénéficiaire. |
| Même clé, contenu différent | Deux requêtes concurrentes de quantités différentes : une réussit, l’autre reçoit `idempotency_conflict`. Le changement de type avec la même clé est aussi refusé. |
| Atomicité complète | Exception PostgreSQL injectée pendant l’écriture d’audit, après mise à jour du solde et insertion du mouvement/événement : annulation de l’ensemble. Le même appel peut ensuite réussir après retrait du défaut. |
| Transfert sans perte | Transferts inverses simultanés entre deux processus ; deux réussites, soldes finaux identiques aux soldes initiaux. Dépassement de capacité à destination : aucun débit source conservé. |
| Première création de solde | Deux crédits simultanés sur un couple encore absent donnent un seul solde contenant leur somme et deux mouvements. |
| Journal réconciliable | Tous les scénarios valides sont comparés à `stock_reconciliation` ; la commande existante est testée. Une modification SQL manuelle du solde est détectée, jamais corrigée silencieusement. |
| Immutabilité et date | Modification et suppression du journal refusées par PostgreSQL ; date serveur UTC bornée par le test ; date fournie par le client et champs inconnus refusés. |

## Autres protections

Les tests couvrent les rôles, la perte d’affectation avant un rejeu, le bénéficiaire tiers hors affectation, le refus d’un tiers pour USER, la désactivation du bénéficiaire, le magasin inactif et le changement de version d’authentification. Les textes nuls/non UTF-8, quantités nulles, négatives hors ajustement, décimales, flottantes, booléennes ou hors bornes, clés invalides et références inconnues sont refusés sans écriture.

Les inventaires en comptage ou en attente de validation bloquent les entrées et l’une ou l’autre extrémité d’un transfert ; l’annulation libère les opérations. Les ajustements signés sont réconciliés au journal. Le plafond de quantité et la saturation de version sont vérifiés. La réponse correspond au schéma `MovementResource` et n’expose pas l’empreinte ni la clé technique ; l’audit ne copie aucun motif, commentaire ou référence libre.

La migration d’événements est testée depuis un schéma vierge, dans la réexécution et le retour arrière complet sans données. Un retour arrière avec événements est refusé ; les lignes restent présentes. La contrainte interdisant une liste vide de magasins est vérifiée.

## Isolation et exploitation

Les nouveaux tests utilisent uniquement des schémas éphémères distincts. Les tests fonctionnels s’annulent par transaction ; les tests de concurrence créent leurs données validées dans un schéma dédié, puis le suppriment dans un bloc de nettoyage. Aucun article, solde ou mouvement de démonstration n’est ajouté à la base locale réelle.

La migration `Version20261018200000` a été appliquée explicitement avec succès en local. La commande `app:stock:reconcile` retourne `consistent: true` et une liste d’écarts vide. Le moteur conserve des événements en attente pour les futurs traitements ; ce lot ne met pas en service l’envoi des alertes ni de nouveaux écrans.

Les erreurs de tests négatifs concernant les migrations protégées et la panne injectée sont attendues. Les corrections effectuées durant la recette ont été incluses dans l’image avant le passage complet réussi.
