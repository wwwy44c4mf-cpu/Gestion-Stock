# Codes-barres et QR codes par caméra — SCAN-01

Les entrées, les consommations et la sélection des retours proposent **Scanner un code-barres ou QR code**. Le navigateur demande l’accès à la caméra arrière. Placer le code entier dans l’image, avec suffisamment de lumière et de recul. À la première lecture, la caméra s’arrête ; **aucun mouvement n’est enregistré automatiquement**. Sélectionner le magasin, saisir la quantité en unités entières et confirmer le formulaire habituel.

Le navigateur doit disposer d’une caméra et ouvrir l’application directement en HTTPS, notamment depuis son adresse Cloudflare. La lecture n’utilise pas l’application photo native. En cas de refus d’autorisation ou d’échec, autoriser la caméra dans les réglages du navigateur ou sélectionner l’article manuellement. La saisie du code au clavier reste aussi disponible. La fermeture du lecteur, le changement de page et le passage en arrière-plan arrêtent la caméra ; une autorisation accordée après fermeture ne la laisse pas tourner.

## Associer un code à un article

Dans la création ou la modification d’un article, la rubrique **Codes-barres et QR codes** accepte un code par ligne. On peut aussi scanner les codes pour remplir cette liste. Enregistrer le formulaire pour conserver l’association. Un article peut posséder jusqu’à 50 codes ; un code appartient à un seul article, même si celui-ci est désactivé. Pour déplacer un code, le retirer de l’ancien article, puis l’ajouter au nouveau. Les modifications respectent la version du catalogue et sont auditées.

Les administrateurs publient directement. Les gestionnaires proposent l’ajout ou le retrait de codes par le formulaire habituel de proposition ; rien ne change avant approbation administrative. Les utilisateurs peuvent scanner les codes déjà publiés mais ne modifient pas leurs associations.

La correspondance est exacte, sensible à la casse, en conservant les zéros initiaux. Les espaces périphériques sont supprimés. La référence interne de l’article n’est pas assimilée automatiquement à un code : l’ajouter explicitement à la liste si nécessaire. Les codes courants EAN/UPC, Code 128/39, ITF, QR et les autres formats reconnus par ZXing peuvent être lus ; la lisibilité dépend du smartphone et de l’emballage. Un code contenant un saut de ligne, un caractère de contrôle ou dépassant 512 caractères est refusé. Le contenu d’un QR code est un identifiant opaque : **aucune URL n’est ouverte, aucun site fabricant n’est interrogé et aucune quantité de carton n’est déduite**. Les formats logistiques contenant lots, dates ou séparateurs GS1 ne sont pas analysés en champs métier ; utiliser un code produit stable compatible ou la sélection manuelle.

Un code inconnu affiche un message. Choisir l’article manuellement et faire associer ce code dans le catalogue ; il n’y a ni création automatique d’article ni entrée de stock implicite. Une nouvelle entrée ou consommation d’un article désactivé reste interdite.

## Retours

Sur **Consommations → Rendre des articles**, le scan filtre les consommations autorisées possédant un reste à rendre pour cet article. **Choisir ensuite la consommation d’origine**, puis saisir la quantité rendue. Le filtre reste présent en changeant de page ; on peut l’effacer pour revoir tous les articles.

L’article désactivé reste retournable. Les droits personnels, magasins actifs, plafonds cumulés, anonymisation, corrections et blocages d’inventaire restent ceux de [STK-07](retours-stock.md). Scanner un article ne donne aucun accès supplémentaire à un magasin ou aux consommations d’une autre personne. La caméra ne crée jamais un retour sans consommation d’origine.

## Technique, confidentialité et mise à jour

Le décodage est effectué dans le navigateur avec des dépendances verrouillées et servies par l’application ; la vidéo et les images ne sont pas envoyées au serveur. Une recherche de code utilise `POST /api/v1/articles/resolve-code`, session et CSRF obligatoires. Le code est transmis dans le corps JSON, sans être placé dans l’adresse. La réponse est descriptive, sans quantité de stock. La caméra est autorisée uniquement sur les réponses réussies des écrans de scan ; microphone et géolocalisation restent interdits. Un double décodage ne répète pas l’opération et une ancienne réponse de recherche ne remplace pas un scan plus récent.

La migration additive **Version20261023010000** ajoute le tableau versionné des codes et son index relationnel unique maintenu par un trigger PostgreSQL. La recherche utilise cet index. Appliquer les migrations explicitement avant le démarrage du nouveau code. Un retour descendant est refusé si des codes, propositions ou réponses de catalogue correspondantes existent ; conserver une sauvegarde avant mise à jour. Les autres fonctionnalités et migrations déjà appliquées ne sont pas réécrites.

Pour l’installation Portainer en cours : conserver le même nom de stack et tous ses volumes, le même `DATABASE_RELEASE_ID`, les secrets et la clé de reprise. Faire et exporter une sauvegarde de l’ancienne version, arrêter les écritures et les services applicatifs, construire les nouvelles images avec un nouveau `RELEASE_ID`, exécuter la migration avec l’image applicative candidate, puis redémarrer et vérifier. Le service `initialize` refuse volontairement une migration implicite au simple redéploiement. Le conteneur `maintenance` reste disponible pour exécuter la migration avec l’image candidate, sans ouvrir le service web ni donner de privilèges administrateur à l’application. Ne pas supprimer les volumes ni contourner ce contrôle. Voir [le guide Portainer](installation-portainer.md) et [OPS-02](deploiement-production.md). L’essai sur le vrai smartphone et les vrais codes de cartons reste une étape d’acceptation avant utilisation courante.

Les tests couvrent codes multiples, unicité et archivage, propositions et permissions, absence d’écriture au scan, filtre de retour personnel, contrôle de version et contraintes SQL ; les tests JavaScript couvrent les promesses tardives, les lectures répétées, le refus de caméra et les recherches concurrentes. Les tests automatisés n’utilisent aucune caméra réelle ni donnée de l’installation Home Assistant.

Sur smartphone, la liste des consommations retournables présente une fiche par consommation, avec son article, son magasin, son bénéficiaire, le reste à rendre et un bouton « Rendre des articles » visible sans défilement horizontal. Sur grand écran, ces informations restent présentées en tableau.

La recette locale du lecteur se lance avec `node --test tests/scan_camera_test.mjs tests/scanner_controller_test.mjs tests/scanner_decode_fixture_test.mjs`. Les dépendances et licences sont décrites dans [frontend](../frontend/README.md).
