# API de Recherche d'Événements

Cette documentation décrit les nouvelles API de recherche d'événements ajoutées au projet TicketUp.

## Endpoints disponibles

### 1. Recherche d'événements par catégorie

**Endpoint:** `GET /api/events/category/{categoryId}`

**Description:** Retourne tous les événements actifs d'une catégorie spécifique, triés par date de début décroissante.

**Paramètres:**

-   `categoryId` (path, requis): ID de la catégorie

**Exemple de requête:**

```bash
GET /api/events/category/1
```

**Réponse (200 OK):**

```json
[
	{
		"id": 1,
		"title": "Concert Rock",
		"startedAt": "2026-02-15T20:00:00+00:00",
		"endAt": "2026-02-15T23:00:00+00:00",
		"status": true,
		"category": {
			"id": 1,
			"name": "Musique",
			"color": "#FF5733"
		}
	}
]
```

---

### 2. Recherche avancée d'événements

**Endpoint:** `GET /api/events/search`

**Description:** Recherche des événements selon plusieurs critères combinables.

**Paramètres de requête (query parameters):**

-   `category` (optionnel): ID de la catégorie (entier)
-   `title` (optionnel): Titre de l'événement - recherche partielle (chaîne)
-   `startDate` (optionnel): Date de début minimum au format `Y-m-d` (ex: 2026-01-15)
-   `endDate` (optionnel): Date de début maximum au format `Y-m-d` (ex: 2026-12-31)

**Note:** Au moins un critère de recherche est requis.

**Exemples de requêtes:**

1. Recherche par catégorie uniquement:

```bash
GET /api/events/search?category=1
```

2. Recherche par titre:

```bash
GET /api/events/search?title=concert
```

3. Recherche par catégorie et période:

```bash
GET /api/events/search?category=2&startDate=2026-02-01&endDate=2026-02-28
```

4. Recherche combinée (catégorie + titre + dates):

```bash
GET /api/events/search?category=1&title=rock&startDate=2026-01-01&endDate=2026-12-31
```

**Réponse (200 OK):**

```json
[
	{
		"id": 1,
		"title": "Concert Rock",
		"startedAt": "2026-02-15T20:00:00+00:00",
		"endAt": "2026-02-15T23:00:00+00:00",
		"status": true,
		"category": {
			"id": 1,
			"name": "Musique",
			"color": "#FF5733"
		}
	}
]
```

**Réponse d'erreur (400 Bad Request):**

```json
{
	"error": "Au moins un critère de recherche est requis (category, title, startDate, endDate)"
}
```

ou

```json
{
	"error": "Format de date invalide pour startDate. Utilisez le format: Y-m-d"
}
```

---

## Détails techniques

### Modifications apportées

1. **EventRepository.php** - Ajout de deux méthodes:

    - `findByCategory(int $categoryId)`: Recherche simple par catégorie
    - `searchEvents(array $criteria)`: Recherche avancée avec critères multiples

2. **GetEventsByCategoryController.php** - Controller pour la recherche par catégorie

3. **SearchEventController.php** - Controller pour la recherche avancée

4. **Event.php** - Ajout de deux opérations API Platform:
    - Opération GET pour `/events/category/{categoryId}`
    - Opération GET pour `/events/search`

### Groupes de sérialisation

Les résultats utilisent le groupe `events:lists` qui inclut:

-   `id`: Identifiant de l'événement
-   `title`: Titre de l'événement
-   `startedAt`: Date de début
-   `endAt`: Date de fin
-   `status`: Statut actif/inactif
-   `category`: Informations de la catégorie (id, name, color)

### Filtres appliqués

-   Seuls les événements avec `status = true` sont retournés
-   Les résultats sont triés par date de début décroissante
-   La recherche de titre est insensible à la casse (recherche partielle avec LIKE)

---

## Tests avec cURL

### Test 1: Récupérer les événements d'une catégorie

```bash
curl -X GET "http://localhost/api/events/category/1" \
  -H "Accept: application/json"
```

### Test 2: Recherche par titre

```bash
curl -X GET "http://localhost/api/events/search?title=concert" \
  -H "Accept: application/json"
```

### Test 3: Recherche par catégorie et dates

```bash
curl -X GET "http://localhost/api/events/search?category=1&startDate=2026-01-01&endDate=2026-12-31" \
  -H "Accept: application/json"
```

---

## Prochaines améliorations possibles

-   Ajouter la pagination pour la recherche avancée
-   Ajouter des filtres supplémentaires (lieu, organisateur, prix)
-   Ajouter un tri personnalisé (par date, par titre, par popularité)
-   Ajouter une recherche full-text sur la description
-   Implémenter un système de cache pour les recherches fréquentes
