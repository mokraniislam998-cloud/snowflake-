# Projet universitaire – Classification d’images IRM de tumeurs cérébrales par CNN et déploiement via API REST

## Résumé
Ce projet présente la conception et la réalisation d’un système de classification automatique d’images IRM du cerveau à l’aide de réseaux de neurones convolutifs (CNN). Le modèle développé permet de distinguer trois types de tumeurs cérébrales : gliome, méningiome et tumeur cérébrale générique. Le système est complété par une API REST construite avec FastAPI afin de rendre le modèle accessible et exploitable dans un contexte applicatif.

---

## 1. Introduction
Le diagnostic des tumeurs cérébrales repose fortement sur l’analyse d’images IRM par des spécialistes. Cette tâche est complexe, chronophage et sujette à une variabilité humaine. L’intégration de techniques d’intelligence artificielle, et plus précisément de l’apprentissage profond, permet d’automatiser une partie de cette analyse et d’assister les professionnels de santé dans la prise de décision.

Ce projet vise à développer un outil de classification d’images médicales basé sur un CNN et à le déployer sous forme de service web.

---

## 2. Objectifs du projet
Les objectifs principaux sont :
- Mettre en place un pipeline complet de classification d’images médicales.
- Concevoir et entraîner un modèle CNN capable de reconnaître différents types de tumeurs cérébrales.
- Évaluer les performances du modèle sur un jeu de test indépendant.
- Déployer le modèle via une API REST pour faciliter son intégration dans une application externe.

---

## 3. Jeu de données et préparation

### 3.1 Source des données
Les images utilisées proviennent d’un dataset public issu de la plateforme Kaggle, regroupant plusieurs types de cancers. Le sous-ensemble "Brain Cancer" a été sélectionné pour ce projet.

### 3.2 Structure des données
Les images sont organisées selon l’arborescence suivante :

dataset/
├── train/
│   ├── brain_glioma/
│   ├── brain_menin/
│   └── brain_tumor/
└── test/
    ├── brain_glioma/
    ├── brain_menin/
    └── brain_tumor/

### 3.3 Prétraitement
Les images subissent les opérations suivantes :
- Redimensionnement à une taille uniforme.
- Conversion en tenseurs PyTorch.
- Préparation pour l’inférence via un pipeline cohérent avec l’entraînement.

---

## 4. Méthodologie

### 4.1 Choix de la technique
Le choix s’est porté sur un réseau de neurones convolutif (CNN) en raison de sa capacité à apprendre automatiquement des caractéristiques visuelles pertinentes dans les images, telles que les contours, textures et formes des tissus cérébraux.

### 4.2 Architecture du modèle
Le modèle implémenté, nommé SimpleCNN, est composé de :
- Cinq blocs convolutionnels successifs comprenant :
  - Convolution 2D
  - Batch Normalization
  - Fonction d’activation ReLU
  - MaxPooling
- Une couche Adaptive Average Pooling afin de normaliser la taille des cartes de caractéristiques.
- Un classifieur fully connected intégrant des couches linéaires et du Dropout pour limiter le surapprentissage.

La couche de sortie comporte trois neurones correspondant aux trois classes étudiées.

---

## 5. Entraînement du modèle

### 5.1 Paramètres d’apprentissage
Les principaux hyperparamètres sont :
- Nombre d’époques : 30
- Taux d’apprentissage : 5e-4
- Taille de batch : 32
- Fonction de perte : Cross-Entropy Loss
- Optimiseur : Adam

### 5.2 Sauvegarde et traçabilité
Durant l’entraînement :
- Le meilleur modèle est sauvegardé sous forme de checkpoint.
- Un historique des performances (loss et accuracy) est enregistré dans un fichier JSON.
- Un dernier checkpoint est également conservé pour permettre une reprise ultérieure de l’entraînement.

---

## 6. Déploiement via FastAPI

### 6.1 Principe
Afin de rendre le modèle exploitable, une API REST a été développée avec FastAPI. Cette API permet à un utilisateur ou à une application cliente d’envoyer une image et de recevoir en retour une prédiction.

### 6.2 Fonctionnalités principales
- Endpoint GET / : vérification de l’état du service.
- Endpoint POST /predict : envoi d’une image IRM et retour :
  - de la classe prédite,
  - du niveau de confiance,
  - des probabilités associées à chaque classe.

Cette approche transforme le modèle en un service intelligent facilement intégrable dans un système médical numérique.

---

## 7. Protocole de test et évaluation
Un script de test automatique a été développé pour :
- Parcourir un ensemble d’images.
- Envoyer chaque image à l’API.
- Récupérer la prédiction et son niveau de confiance.

Un seuil de confiance de 70 % est utilisé afin de considérer uniquement les prédictions jugées suffisamment fiables.

Les indicateurs suivis incluent :
- La perte (loss) sur les ensembles d’entraînement et de validation.
- La précision (accuracy) sur les mêmes ensembles.
- Le taux de prédictions correctes à forte confiance.

---

## 8. Limites et perspectives

### 8.1 Limites
- Taille limitée du dataset pouvant influencer la généralisation.
- Absence actuelle de normalisation avancée et d’augmentation de données.
- Évaluation principalement basée sur l’accuracy, sans analyse détaillée par classe.

### 8.2 Perspectives d’amélioration
- Intégration de techniques de transfer learning (ResNet, EfficientNet).
- Ajout de méthodes d’explicabilité (Grad-CAM) pour interpréter les décisions du modèle.
- Renforcement de la sécurité de l’API (authentification, journalisation).
- Déploiement via conteneurs Docker pour une meilleure portabilité.

---

## 9. Conclusion
Ce projet met en évidence l’apport des techniques d’apprentissage profond dans le domaine de l’imagerie médicale. L’utilisation d’un CNN permet d’automatiser la classification de tumeurs cérébrales à partir d’IRM, tandis que le déploiement via FastAPI offre une solution concrète pour l’intégration du modèle dans un environnement applicatif réel.

L’ensemble constitue une base solide pour de futurs travaux académiques ou professionnels dans le domaine de l’intelligence artificielle appliquée à la santé.

---

## Avertissement
Ce système est un outil d’aide à la décision et ne remplace en aucun cas l’avis d’un professionnel de santé qualifié.

---



---

## Licence
MIT
