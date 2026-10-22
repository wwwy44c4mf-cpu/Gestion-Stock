# Guide utilisateur

## Se connecter et choisir un magasin

Ouvrir l’adresse fournie par l’administrateur, puis se connecter avec son courriel et son mot de passe. En cas d’oubli, contacter l’administrateur : la récupération autonome du mot de passe (AUTH-04) reste à développer, même si SMTP est configuré. Dans **Profil**, choisir son fuseau horaire. Les dates des opérations sont fixées à l’enregistrement ; une date passée ne peut pas être saisie.

Le sélecteur **Magasin** limite l’affichage aux magasins autorisés. « Tous mes magasins » regroupe ce périmètre, sans accorder de droits supplémentaires. Un magasin désactivé n’est visible que par les administrateurs. Une session inactive expire après 30 minutes, avec une limite absolue de 12 heures ; une modification des accès peut imposer une reconnexion.

## Consulter et consommer

Dans **Articles**, rechercher la référence ou le nom. Dans **Stocks**, consulter les quantités, y compris les articles jamais mouvementés affichés à zéro. L’unité unique est « unité » et les quantités sont entières. Les totaux ne comprennent que les magasins auxquels on a accès.

Pour sortir des articles, ouvrir **Consommations**, choisir article et magasin, saisir une quantité positive, éventuellement une référence d’intervention et un commentaire, puis confirmer. Le stock disponible est vérifié au moment de la validation : le solde peut avoir changé depuis l’ouverture de la page. Une insuffisance ou un inventaire bloquant refuse la sortie sans modifier le stock. Un double envoi avec la même clé ne crée pas une seconde sortie.

Un utilisateur saisit pour lui-même. Un administrateur ou gestionnaire autorisé peut choisir n’importe quel technicien actif, sans exiger l’affectation de ce bénéficiaire au magasin. Vérifier sur la confirmation le bénéficiaire, distinct de la personne ayant saisi l’opération. [Détails et erreurs](consommations.md).

## Rendre des articles

Depuis sa consommation, choisir **Rendre des articles**, puis la quantité réellement rendue. Les retours sont liés à l’origine et reviennent dans son magasin ; leur cumul ne peut pas dépasser la quantité encore retournable. On peut rendre ses propres articles même après retrait de son affectation si le magasin reste actif. Un inventaire bloque aussi les retours. [Guide des retours](retours-stock.md).

## Historique et statistiques personnels

**Consommations** conserve les sorties, retours et lignes corrigées ou annulées avec leur statut. Les statistiques distinguent sorties et retours ; un retour compte à sa date réelle. Les filtres peuvent porter sur la période et les articles. Les données détachées de l’identité après un an disparaissent des vues personnelles, tout en restant dans les volumes globaux autorisés. [Historique](historique-consommations.md) · [Statistiques](statistiques-consommation.md).

## Si une saisie est incorrecte

Contacter un gestionnaire : les administrateurs et gestionnaires autorisés peuvent corriger directement la quantité affichée dans le formulaire de correction et renseigner un motif. L’application conserve l’original et trace des mouvements compensateurs ; ne pas saisir un faux retour pour dissimuler une erreur. Un échec d’écran ne prouve pas qu’aucune écriture n’a eu lieu : vérifier l’historique avant de recommencer avec une nouvelle demande.

Les menus de gestion supplémentaires suivent [la matrice des permissions](controle-acces.md). Aucun utilisateur ne doit communiquer son mot de passe ou exporter des informations nominatives dans une issue GitHub.
