# Guide d'utilisation

Ce guide explique comment consulter le forum, créer un sujet et répondre à un message.

## Accéder au forum

Ouvrez http://localhost:8090 dans votre navigateur.

Si l'application n'est pas encore démarrée, voir la section « Installation et commandes » de la [documentation technique](technique.md).

## Organisation du forum

Le forum est organisé en trois niveaux :

- **Catégories** : les grands thèmes (Général, PHP, Symfony...).
- **Sujets** : une discussion ouverte dans une catégorie, avec un titre et un premier message.
- **Réponses** : les messages publiés à la suite d'un sujet.

Le lien **Forum** en haut de chaque page ramène à l'accueil.

## Parcourir le forum

### Page d'accueil

La page d'accueil liste les catégories par ordre alphabétique. Pour chacune :

- le **nombre de sujets** qu'elle contient ;
- la **date du dernier sujet** publié (un tiret s'il n'y en a aucun).

Cliquez sur le nom d'une catégorie pour l'ouvrir.

### Page d'une catégorie

Les sujets sont affichés du plus récent au plus ancien. Pour chacun :

- le titre, à cliquer pour lire la discussion ;
- l'auteur (« par ... ») ;
- le nombre de **réponses** ;
- la date de création.

Le fil d'Ariane en haut de la page (« Forum / Nom de la catégorie ») permet de revenir à l'accueil.

### Page d'un sujet

Le premier message, celui de l'auteur du sujet, est mis en avant par une bordure de couleur et le badge **Auteur**. Les réponses suivent dans l'ordre chronologique, de la plus ancienne à la plus récente.

Chaque message indique le pseudo de son auteur ainsi que la date et l'heure de publication. Les retours à la ligne saisis sont conservés à l'affichage.

Le fil d'Ariane (« Forum / Nom de la catégorie ») permet de remonter à la catégorie ou à l'accueil.

## Créer un sujet

1. Ouvrez la catégorie dans laquelle vous voulez publier.
2. En bas de la page, remplissez le formulaire **Nouveau sujet** :

   | Champ | Contenu | Limite |
   |---|---|---|
   | Titre | Le titre de la discussion | 150 caractères |
   | Pseudo | Le nom sous lequel vous apparaîtrez | 50 caractères |
   | Message | Le premier message du sujet | 10 000 caractères |

3. Cliquez sur **Publier**.

Une fois le sujet publié, vous êtes redirigé directement sur sa page.

## Répondre à un sujet

1. Ouvrez le sujet.
2. En bas de la page, remplissez le formulaire **Répondre** :

   | Champ | Contenu | Limite |
   |---|---|---|
   | Pseudo | Le nom sous lequel vous apparaîtrez | 50 caractères |
   | Message | Votre réponse | 10 000 caractères |

3. Cliquez sur **Envoyer**.

Votre réponse apparaît à la fin de la discussion.

## En cas d'erreur de saisie

Tous les champs sont obligatoires. Le navigateur signale en général un champ vide avant même l'envoi. Si le serveur refuse malgré tout la saisie, le formulaire est réaffiché : le champ concerné est encadré en rouge et le message d'erreur s'affiche au-dessus. Corrigez-le puis renvoyez le formulaire.

## Bon à savoir

- **Pas de compte** : aucune inscription ni connexion n'est nécessaire. Le pseudo est libre et doit être saisi à chaque publication.
- **Pas de modification** : un sujet ou une réponse ne peut plus être modifié une fois publié. Relisez-vous avant d'envoyer.
- **Pas de suppression depuis les pages du forum.**
- **Catégories** : la liste des catégories est gérée par l'administrateur, elle ne peut pas être modifiée depuis les pages du forum.
- **Thème sombre** : le forum suit automatiquement le thème clair ou sombre de votre système.
