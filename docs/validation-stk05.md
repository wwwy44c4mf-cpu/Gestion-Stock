# Validation STK-05 — Ajustements et corrections

Recette finale Docker du 18 octobre 2026 : **185 tests PHP / 2 653 assertions et quatre validations indépendantes OpenAPI réussis**. Commande `docker compose run --rm test`, sortie 0, durée PHPUnit 91,668 s. Les 18 nouveaux tests comprennent les scénarios HTTP/métier, la concurrence réelle et la protection des liens de correction. Le défaut de redirection d’un ajustement négatif relevé en relecture est corrigé et couvert dans cette recette finale.

## Scénarios vérifiés

- Entrée 10 corrigée à 8 après consommation de 5 : stock final 3. Le journal original reste identique ; contre-mouvement de 10 et remplacement de 8 liés, référence conservée, nouvel opérateur, audits et événements atomiques ; réconciliation sans écart. La réponse suit `MovementCorrectionResource`.
- Rejeu identique : même résultat et aucun nouveau mouvement. Contenu différent avec la même clé : 409. Deuxième correction ou annulation de l’origine : 409. Un contre-mouvement ne peut pas être compensé ; le remplacement peut faire l’objet d’une nouvelle correction.
- Annulation exacte d’une entrée : stock remis à zéro, origine conservée, rejeu sans deuxième débit. Consommation corrigée : bénéficiaire extérieur au magasin et commentaire préservés ; confirmation personnelle signalant la correction avec lien autorisé. Annulation de son remplacement : stock restauré.
- Insuffisance de stock, inventaire in_progress ou pending_validation : aucune variation du solde, des mouvements, des audits ou des événements. Un transfert exige les deux magasins : gestionnaire limité à une extrémité refusé, aucun lien de correction exposé ; inventaire sur l’autre magasin bloquant ; correction valide réconcilie les deux soldes.
- USER refusé sur correction et ajustement ; gestionnaire sans affectation, droits retirés après ouverture et magasin désactivé refusés. Quantité zéro, négative dans une correction, flottant, chaîne, booléen, null, dépassement de borne et quantité inchangée refusés. Motif vide, espaces seuls, trop long ou nul binaire ; champs ajoutés, identifiant invalide et clé invalide refusés. CSRF contrôlé.
- Formulaire de correction sans JavaScript : quantité et motif saisis, redirection 303 vers le stock, original marqué corrigé, motif échappé consultable. Double soumission sans deuxième effet. Erreur de quantité : valeurs et clé conservées ; tableaux et CSRF forgés refusés. Le formulaire d’annulation exige un envoi explicite, son GET n’écrit rien.
- Ajustement signé : retrait de 2, correction de ce retrait à 3, ajout de 2 sur article désactivé et soldes réconciliés. Ajustement négatif via formulaire : redirection vers le magasin source, confirmation et double envoi contrôlés. Quantités invalides, stock négatif et dépassement de capacité lors d’une correction refusés sans écriture.
- Bénéficiaire inactif et article inactif bloquent le remplacement d’une consommation ; annulation autorisée avec stock et magasin valides. Les ajustements issus d’un inventaire clôturé sont protégés. Une consommation avec un retour actif ne peut pas être corrigée/annulée ; un retour corrigé reste borné par sa consommation d’origine.
- Panne injectée avant création du lien de correction : réponse 500 et annulation intégrale du solde, de la paire de mouvements, des événements et des audits.
- Deux vrais processus PostgreSQL : corrections différentes du même mouvement (201/409), rejeu concurrent identique (201/201 avec même résultat), annulations concurrentes (201/409). Un seul effet final, aucune double compensation, audits/événements cohérents et réconciliation sans écart.
- Migration vierge, retour arrière à vide et réapplication vérifiés dans SchemaTest. Liens de correction invalides et mutation/suppression refusés ; retour arrière avec corrections existantes explicitement bloqué, sans perte des liens.

Les tests d’écriture utilisent des schémas PostgreSQL isolés et supprimés ou annulés en fin de scénario. La suite existante reste incluse : autorisations, authentification, catalogue, propositions, stocks, consommations et concurrence. Les erreurs émises par la panne injectée et les migrations protégées sont attendues.

## Interface et environnement local

Navigation réelle depuis Stocks vers la fiche, puis Corriger sur une entrée existante. Le formulaire distingue quantité correcte et différence, présente le mouvement initial et exige le motif. Contrôle visuel ordinateur et mobile 390 × 844 : largeur document et largeur utile égales à 375 pixels, aucun débordement horizontal, champs et bouton accessibles en bas de page. Taille normale rétablie. Formulaire d’ajustement ouvert avec magasin/article présélectionnés et explication des signes. Aucun formulaire d’écriture soumis sur les données locales réelles ; capture `/tmp/gestion-stock-stk05.jpg`.

Migration `Version20261018223000` appliquée en développement : huit migrations, aucune ancienne donnée réécrite. Avant et après : mêmes deux identifiants de mouvement (entrée 10 et consommation 2), solde 8, version 2 ; zéro correction de démonstration, réconciliation sans écart. Les cinq services sont sains après actualisation.

Contrat : **60 opérations et 91 schémas**, **36 disponibles / 24 planifiées**. Ajout de `correctMovement`, disponibilité de `createAdjustment` et `reverseMovement`, schémas de correction définis. Les lectures globales d’historique et statistiques restent dans leurs lots ; le journal API local ne divulgue aucun magasin distant.

Livraison pour revue : branche `codex/stk-05-stock-corrections`, basée sur main après intégration de la PR #65. [Guide](corrections-stock.md).
