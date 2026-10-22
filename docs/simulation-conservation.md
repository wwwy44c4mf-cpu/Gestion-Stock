# RGPD-01 — Simulation de conservation

La commande `php bin/console app:privacy:preview` produit un rapport JSON agrégé, sans identité, commentaire, référence ni identifiant de personne. Elle ne propose aucune option d’effacement. L’exécution contrôlée distincte est décrite dans [le guide de conservation](conservation-donnees.md).

Le rapport compte les mouvements, lignes d’audit, notifications et campagnes arrivés à échéance, distingue les inventaires et propositions terminés des dossiers ouverts à préserver, et détecte les corrections récentes de consommations anciennes. Les nombres indiquent les lignes ayant atteint le délai : ils ne constituent pas encore le nombre final d’informations effacées, qui dépend aussi des copies et liens à traiter.

Le délai utilisé est une année calendaire, à l’heure anniversaire incluse, calculée en UTC. Le 29 février arrive à échéance le 28 février de l’année suivante, à la même heure. L’ensemble du rapport utilise une seule date de référence et un instantané cohérent de la base. La transaction est explicitement en lecture seule ; un échec l’annule. Aucun traitement ne peut être lancé par une requête web.

L’éligibilité des identités de comptes désactivés utilise la date dédiée `deactivated_at` et exclut les personnes encore liées à des dossiers ouverts. Une modification du profil ne décale pas cette date ; une réactivation la remet à zéro.

La commande nécessite l’accès opérateur au conteneur de l’application ; elle ne change ni les stocks, ni les historiques, ni les comptes. Aucun envoi de courriel n’est déclenché.

Les règles métier déjà recueillies figurent dans la [cartographie](cartographie-donnees-personnelles.md) et le [plan de recette](recette-rgpd01-preparation.md). Le guide de conservation documente l’effacement, la planification à installer sur le serveur et le verrouillage après restauration.
