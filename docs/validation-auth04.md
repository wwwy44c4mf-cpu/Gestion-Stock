# Validation AUTH-04

## Périmètre

Issue #8 : demande et confirmation via interface sans JavaScript et API, jeton temporaire à usage unique, envoi asynchrone, absence d’énumération et de secrets dans les journaux. Aucun SMTP réel n’est utilisé ; les bases et comptes de recette sont fictifs et isolés.

## Contrôles ciblés

La première recette ciblée a réussi : 19 tests / 234 assertions (réinitialisation, concurrence et notifications). Les tests de concurrence emploient deux processus et connexions PostgreSQL indépendants : un seul lien mis en file face à deux demandes ; une seule confirmation réussit face à deux consommateurs du même lien.

Un contrôle supplémentaire avec noyaux de production et véritables fichiers/cookies de session PHP a réussi : 1 test / 10 assertions. Deux anciennes sessions ainsi que la session anonyme restent non connectées après confirmation ; une nouvelle connexion avec le nouveau mot de passe réussit. Les contrôles de livraison, notification après changement et la suite globale sont également exécutés dans la recette finale.

Cas couverts : réponse générique, adresse inconnue/inactive et compte de développement hors dev, limites IP et compte, CSRF, formats et tailles, empreinte seule en base, sélecteur seul en outbox, file opaque, modèles HTML/texte, ancien lien remplacé/expiré/consommé, compte modifié, destinataire incorrect, mot de passe faible/refus de confirmation, retrait de l’URL, absence de jeton dans le HTML, redirection sans connexion automatique, rollback et révocation des sessions.

## État

La recette globale locale a réussi ses 415 tests PHP / 5 258 assertions, ses 4 contrôles OpenAPI et les 5 tests du banc. Le contrôle natif supplémentaire (1 test / 10 assertions) est distinct de ce premier lancement global ; la CI doit l’inclure dans la suite finale. Les contrôles HTTP/charge et GitHub du commit final sont à vérifier avant revue. Le [guide](reinitialisation-mot-de-passe.md) décrit les limites de temporisation, de SMTP et de rotation du secret. Aucune migration ni modification des données de développement.
