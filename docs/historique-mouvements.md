# Historique des mouvements — STK-08

Le menu **Mouvements** ouvre `/mouvements` pour les administrateurs et les gestionnaires. Le journal conserve les entrées, consommations, ajustements, transferts, retours et annulations comme opérations distinctes. Consulter le journal ne crée ni solde ni mouvement.

## Recherche

Les filtres se combinent : période, article (y compris désactivé), magasin autorisé, type, opérateur et technicien bénéficiaire. Pour un retour ou son annulation, le bénéficiaire est celui de la consommation d’origine. Les personnes proposées proviennent uniquement des opérations dont les détails sont autorisés ; aucun courriel n’est affiché. Un compte anonymisé apparaît sous « Compte anonymisé ».

Les dates de début et de fin incluent toute la journée dans le fuseau du compte, indiqué à l’écran. La borne supérieure correspond au lendemain à minuit, y compris lors des journées de 23 ou 25 heures. Les instants restent stockés en UTC. Les tris date/quantité sont stables grâce à l’identifiant ; 25 résultats par défaut, de 1 à 100 par page. L’écran présente les plus récents d’abord. La pagination conserve les filtres.

Le magasin sélectionné dans l’en-tête sert de filtre initial. Choisir « Tous mes magasins autorisés » ou « Réinitialiser » permet de rechercher dans tout son périmètre.

## Confidentialité

L’administrateur peut consulter tous les magasins, y compris désactivés. Le gestionnaire ne consulte que les magasins actifs qui lui sont affectés. Les mouvements touchant un magasin désactivé restent réservés aux administrateurs.

**Décision utilisateur du 20 octobre 2026 :** si un seul des deux magasins d’un transfert est autorisé, le journal affiche une ligne limitée au magasin accessible. La date, le type, l’article et la quantité restent visibles. Le magasin extérieur, les personnes, les textes libres et les liens d’opérations sont masqués. Cette projection est appliquée avant les filtres et le comptage : filtrer sur une personne masquée ne révèle rien. Un filtre explicite sur un magasin interdit renvoie 404. Le détail conserve la même restriction.

Les utilisateurs simples n’ont pas accès au journal général. Leur historique personnel relève de CON-01. Le journal local d’une fiche stock conserve sa projection existante, sans identités ni magasin distant.

## Détails et corrections

Le détail montre les magasins autorisés, les personnes et les motifs disponibles. Il propose les liens vers la consommation, le retour, le transfert et le mouvement d’origine lorsque leur périmètre est entièrement autorisé. Les corrections et annulations déjà enregistrées restent traçables. L’action de correction reste soumise aux droits d’écriture sur tous les magasins et aux règles STK-05 ; aucun mouvement validé n’est modifié en place.

## API

- `GET /api/v1/movements` : liste paginée, filtres `date_from`, `date_to`, `article_id`, `store_id`, `type`, `author_id`, `consumer_id`, `sort`, `order`, `page`, `limit`.
- `GET /api/v1/movements/{id}` : détail de lecture soumis au même périmètre.

Les schémas `HistoryMovement`, `HistoryMovementPage` et `HistoryMovementResource` décrivent la lecture et son indicateur `restricted`. Les identités peuvent être nulles dans une ligne restreinte. Les réponses des écritures conservent le schéma `MovementResource` existant. L’API garde son ordre contractuel croissant par défaut. Les paramètres inconnus, dates impossibles et valeurs hors limites renvoient 422 ; ressource absente ou interdite : 404 ; rôle utilisateur simple : 403 ; session absente : 401.

Aucune migration supplémentaire.
