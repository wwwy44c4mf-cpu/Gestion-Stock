# Maquette des évolutions — 13 novembre 2026

## Livraison

Préparer une maquette locale complète regroupant les demandes ci-dessous. La faire tester et valider avant toute publication du code ou mise à jour de production. Aucune suppression de données de production pendant le développement. Poser les questions une par une.

## Demandes et décisions

| Fonction | Demande confirmée | Précisions restantes |
| --- | --- | --- |
| Création des articles | Les gestionnaires créent directement un article, sans validation administrateur. | La modification des articles n’est pas étendue implicitement par cette décision. |
| Suppression définitive | Vrais articles et articles de test, stocks et historiques ; avertissement avant confirmation ; administrateurs et gestionnaires seulement. Gestionnaire autorisé uniquement sur tous les magasins concernés. | Prototype local disponible ; aucune suppression sur le serveur. |
| Ajustement de stock | Ajouter le scan code-barres/QR pour sélectionner un article, comme dans les autres mouvements. | Reprendre les règles existantes de quantité, motif et autorisation. |
| Retour sans sortie préalable | Autoriser un dépôt de matériel qui ne possède pas de consommation d’origine dans l’application, notamment les sorties antérieures au démarrage. Tous les techniciens, gestionnaires et administrateurs peuvent le saisir. | Formulaire unique « Retour » : rattachement aux consommations existantes, puis réintégration automatique de l’éventuel surplus sans sortie d’origine. Un seul scan, magasin et quantité ; aucun bouton supplémentaire. |
| Article commandé | Administrateurs et gestionnaires indiquent qu’un article est commandé ; dans Stocks les utilisateurs voient notamment « En rupture / commandé ». | Le gestionnaire marque commandé ; à réception il saisit une entrée ou un ajustement. Aucune quantité automatique. |
| Outillage CA | Sous Consommation, une section Outillage CA avec outils à cocher. Exemplaires distincts : deux scies donnent deux cases. Exemplaire emprunté grisé et liste des détenteurs (« technicien X a… »). Administration des outils par gestionnaires et administrateurs. | CA = Curatif Antennaire. Les outils sont rattachés à un magasin : Blaise Pascal CA ; futur magasin Chatillon sur thouet CA, pas encore créé. Les techniciens empruntent et rendent eux-mêmes leurs outils, sans validation. Chaque outil revient uniquement dans son magasin d’origine. Un unique bouton « Rendre les outils » restitue en une fois tous les outils d’une personne dans ce magasin ; les outils empruntés dans un autre magasin restent sortis. |
| Tourets de câble | Catégorie touret avec suivi de longueur : réception 500 m, utilisation 100 m, reste 400 m. | Chaque touret est suivi séparément ; les longueurs sont saisies en mètres entiers. Référence individuelle unique. Le stock compte les tourets non vides ; leur longueur est suivie séparément. Les mouvements ordinaires sur cet article sont bloqués pour éviter de désynchroniser les mètres. |
| Boutons de stock | Casages et export de mêmes dimensions que les autres actions. | Actions de mêmes dimensions ; export retiré. |
| Export Excel | Retirer la fonction « Exporter en Excel ». | La synchronisation du fichier existant ci-dessous est une demande distincte. |
| Fichier Excel existant | Mettre à jour le fichier existant à chaque mouvement d’article pour le CA. | Fichier actuellement sur le PC de l’exploitant. Son transfert/import sur le Mac est réservé à la dernière étape, à sa demande. CA = Curatif Antennaire. Structure et modalités de synchronisation à préciser lors de cet import, avant tout accès ou écriture. |
| Navigation utilisateur | Afficher seulement Tableau de bord, Articles, Stock, Consommation pour le rôle utilisateur. | Conserver l’accès au profil et à la déconnexion. |
| Confirmation de sortie | Retirer après validation l’indication du nombre restant à rendre : la plupart des articles ne reviennent pas. | Ne pas supprimer les contrôles de quantité nécessaires aux retours effectivement saisis. |

## Organisation

Suivre chaque fonctionnalité par une issue GitHub une fois son périmètre établi, découper les tâches dans suivi.md et vérifier les droits, les mouvements et l’affichage sur mobile. Les essais utilisent uniquement des données fictives. La production reste sur la livraison a9d72275fc83e79795fbabde6cdd1ee7818dcbe8 tant que la maquette complète n’est pas validée.

## Suivi GitHub

| Fonction | Issue |
| --- | --- |
| Suppression définitive | [ART-04 #114](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/114) |
| Alignement des actions | [UI-04 #115](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/115) |
| Création par gestionnaire | [CAT-03 #116](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/116) |
| Scan ajustement | [SCAN-03 #117](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/117) |
| Retour sans origine | [RET-03 #118](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/118) |
| Article commandé | [STK-09 #119](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/119) |
| Outillage CA | [OUT-01 #120](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/120) |
| Tourets | [CAB-01 #121](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/121) |
| Navigation et confirmation | [UI-05 #122](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/122) |
| Export et futur fichier existant | [EXP-02 #123](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/123) |

## Essai local

Adresse : http://127.0.0.1:18791. Les comptes sont fictifs (`admin@example.test`, `manager-a@example.test`, `technician-a@example.test`) ; mot de passe de démonstration : `QA-fixture-only-2026!`.

Les exemples incluent deux scies individualisées, un outil emprunté, un article en rupture commandé et deux tourets (400 m sur 500 m, et 300 m sur 300 m). Le magasin « Blaise Pascal CA — démonstration » est fictif. Le futur magasin Chatillon sur thouet CA n’est pas créé sur le serveur.

Les emprunts ouverts empêchent la suppression du compte et la fermeture du magasin concernés. Les emprunts terminés et les mouvements de câble suivent la conservation nominative d’un an. La caméra physique reste à essayer sur les smartphones après validation locale ; les essais automatisés vérifient le parcours du scanner, pas le matériel.

La synchronisation du fichier Excel existant reste la dernière étape, en attente du fichier de l’exploitant. Aucun modèle de fichier n’a été inventé et aucune synchronisation n’est annoncée comme opérationnelle.

## Validation de l’exploitant

Le 13 novembre 2026, l’exploitant a validé la maquette et demandé sa mise en production. Publication et contrôles de livraison autorisés ; l’installation effective sera confirmée après sauvegarde, migration et redéploiement dans Portainer. L’intégration du fichier Excel existant reste différée.
