# Cycle des alertes — ALT-02

Le niveau d’un stock est recalculé dans la transaction qui modifie sa quantité ou ses seuils :

1. **Critique** si un seuil critique est configuré et que la quantité lui est inférieure ou égale.
2. **Bas** si le seuil bas est configuré et atteint, lorsque le niveau critique ne s’applique pas.
3. **Normal** dans les autres cas.

Zéro est un seuil valide ; un seuil vide ne déclenche rien. Le niveau critique est prioritaire, y compris lorsque les deux seuils sont égaux. Le niveau « En rupture » de la consultation reste un indicateur de quantité nulle ; il ne crée pas une alerte lorsque les seuils ne sont pas configurés.

## Transitions et historique

Un changement de niveau clôt l’ancienne alerte et ouvre celle du nouveau niveau si nécessaire. Il ne peut exister qu’une alerte active par article et magasin. Ainsi, critique vers bas clôt l’alerte critique et crée une alerte basse ; bas vers normal résout l’alerte sans en ouvrir une autre. Chaque changement produit une ligne immuable dans `stock_alert_transitions`, avec niveaux avant/après, quantité, seuils, date UTC et origine. Les alertes résolues restent immuables.

Lorsque la quantité varie dans le même niveau, aucun nouvel événement ou courriel n’est créé. Modifier un seuil sans changer de niveau actualise le seuil de l’alerte active ; le franchissement initial conserve sa référence dans l’événement historique et l’édition des seuils garde son audit. Une lecture, un formulaire rouvert ou une opération rejouée ne dupliquent pas les événements.

Le recalcul se fait au niveau de la ligne de solde PostgreSQL, déjà verrouillée par l’écriture. Il couvre entrées, consommations, transferts (chaque magasin), retours, ajustements, annulations, corrections et clôtures d’inventaire. Les corrections écrivent le solde net : les écritures comptables de remplacement ne créent pas de faux franchissements intermédiaires. Le simple comptage d’inventaire ne change pas le stock et ne déclenche pas d’alerte.

## Courriels immédiats

L’entrée dans un niveau bas ou critique enregistre une notification pour chaque administrateur actif et gestionnaire actif affecté au magasin actif. Les comptes réservés au développement sont exclus. Les comptes et courriels uniques empêchent les doublons de destinataire ; une clé propre à la transition et au destinataire protège l’événement. Aucun message de rétablissement n’est envoyé : la résolution reste dans l’historique.

Les événements et notifications sont enregistrés avec la modification métier, sans contacter SMTP. Une erreur d’insertion annule ensemble solde, mouvement, alerte et événement. Une indisponibilité SMTP après validation laisse le mouvement validé et déclenche les reprises bornées de MAIL-01. Les droits du destinataire sont revérifiés à la livraison.

L’envoi s’applique à toute heure, via la file de MAIL-01, sans attendre le récapitulatif quotidien. Les notifications restent en attente lorsque l’envoi réel est désactivé. La limite de répétition après une acceptation SMTP ambiguë reste celle décrite dans le [guide des courriels](courriels.md).

Les modèles acceptent les noms accentués à leur longueur autorisée. Les caractères de contrôle des noms sont remplacés par des espaces dans le courriel ; les noms du catalogue restent conservés.

## Installation

La migration 13 installe le journal et le recalcul automatique. Elle initialise les alertes des soldes déjà présents sans modifier quantités, versions, seuils ou mouvements. Un solde configuré déjà bas ou critique produit son événement initial et ses notifications ; les stocks normaux n’en produisent aucun. Le retour arrière est refusé si des transitions existent, afin de préserver l’historique.

L’écran des alertes et la configuration des destinataires seront livrés dans ALT-03. Le récapitulatif quotidien de 08 h sera raccordé dans MAIL-03. Aucune nouvelle route API n’est exposée dans ALT-02.
