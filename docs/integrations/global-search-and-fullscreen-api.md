# Global Search & Viewport Preferences REST API — Multi-Platform Integration Guide

**Version:** 1.0.0 Enterprise Production Ready  
**Base URL:** `https://your-institution-domain.org/api/v1`  
**Authentication Support:** Dual-Mode (`auth.api_key` via `X-API-Key` Header & `auth:sanctum` via `Authorization: Bearer <token>`)  
**Security Scopes:** `read:search`, `manage:search`, `*`

---

## 1. Architectural Overview & Value Proposition

The **Global Search & Viewport Preferences Module** provides a high-performance, real-time command palette and unified search infrastructure across the entire administrative ecosystem. Designed for cross-platform integration across **Web**, **Mobile Applications** (Flutter, React Native), and **Desktop Shells** (Electron, WPF .NET 8), this API enables clients to:

1. **Perform Unified System Discovery:** Query administrative navigation modules, settings pages, and interactive quick actions with auto-suggestions.
2. **Execute Role-Based Entity Search:** Concurrently search live database entities—including **Students**, **Courses**, **Batches**, **Library Books**, **Users/Staff**, **Support Tickets**, and **Study Materials**—with strict access control (RBAC). A client never receives records their assigned permissions do not grant them rights to see.
3. **Synchronize Viewport Modes:** Retrieve and set default viewport modes (`normal` standard window vs `fullscreen` auto-expanded workspace), persisting preferences per-user or institutional-wide.

---

## 2. Security Scopes & Access Control

| Scope Key | Classification | Description & Capabilities |
| :--- | :--- | :--- |
| `read:search` | Query & Discovery | Search modules, permissible data records, fetch navigation taxonomy, and inspect viewport preferences. |
| `manage:search` | Configuration | Update personal or institution-wide viewport display modes and command palette preferences. |
| `*` | Root Access | Unrestricted access across all search and administrative endpoints. |

### Role-Based Access Enforcement

Entity search results strictly respect user capabilities:
- **`students.view`**: Enables student master dossier searching (Name, Roll, Admission Number, Email, Phone).
- **`courses.manage`**: Enables course catalog searching (Title, Course Code).
- **`batches.manage`**: Enables batch and cohort searching (Batch Name, Code).
- **`library.books.manage` / `library.view`**: Enables book catalog and ISBN searching.
- **`users.view`**: Enables administrative and faculty staff directory searching.
- **`tickets.view`**: Enables helpdesk and support ticket searching.
- **`study_materials.view`**: Enables syllabus, notes, and study repository searching.
- **Super Administrator**: Automatic bypass granting search capability across all modules and entities.

---

## 3. Comprehensive RESTful API Reference

### 3.1 Execute Unified Global Search
**Endpoint:** `GET /api/v1/search/global`  
**Required Scope:** `read:search`  
**Query Parameters:**
- `q` (string, optional, max 100): Query search keyword or term (e.g. `prelims`, `civil`, `invoice`, `patel`).
- `category` (string, optional): Filter results to a specific category (`all`, `Students`, `Academics`, `Library Management`, `Fees & Payments`, `Quick Actions`).
- `limit` (integer, optional, default: 20, max: 50): Maximum number of results to return.

#### Sample Request (cURL)
```bash
curl -X GET "https://mis.sntcssc.org/api/v1/search/global?q=civil&limit=5" \
  -H "X-API-Key: sntc_live_9f8e7d6c5b4a3210..." \
  -H "Accept: application/json"
```

#### Sample Response (200 OK)
```json
{
  "success": true,
  "data": {
    "query": "civil",
    "total": 4,
    "categories": [
      "Academics",
      "Alumni",
      "Students"
    ],
    "items": [
      {
        "id": "course_1",
        "type": "course",
        "title": "Civil Services Foundation Program 2026",
        "subtitle": "Code: CS-2026-F1 • ₹15,000.00",
        "category": "Academics",
        "description": "Course: Civil Services Foundation Program 2026 (CS-2026-F1)",
        "url": "https://mis.sntcssc.org/admin/courses?search=CS-2026-F1",
        "icon": "graduation-cap",
        "badge": "Course",
        "score": 40
      },
      {
        "id": "student_12",
        "type": "student",
        "title": "Pooja Roy",
        "subtitle": "ID: 2026-0042 • pooja.roy@example.com",
        "category": "Students",
        "description": "Student profile of Pooja Roy (Phone: +919876543210)",
        "url": "https://mis.sntcssc.org/admin/students?search=2026-0042",
        "icon": "user",
        "badge": "Student",
        "score": 35
      }
    ]
  },
  "meta": {
    "query": "civil",
    "total_results": 2,
    "categories": [
      "Academics",
      "Students"
    ],
    "user_id": 1,
    "role": "Super Administrator"
  }
}
```

---

### 3.2 Retrieve Hierarchical Modules Catalog
**Endpoint:** `GET /api/v1/search/modules`  
**Required Scope:** `read:search`  
**Description:** Retrieves all admin panel modules, submodules, navigation links, and quick actions permissible to the caller. Essential for dynamic client side navigation drawer and native command palette building.

#### Sample Request (cURL)
```bash
curl -X GET "https://mis.sntcssc.org/api/v1/search/modules" \
  -H "X-API-Key: sntc_live_9f8e7d6c5b4a3210..." \
  -H "Accept: application/json"
```

#### Sample Response (200 OK)
```json
{
  "success": true,
  "data": {
    "modules": [
      {
        "category": "Students",
        "count": 7,
        "items": [
          {
            "id": "nav_students",
            "title": "Students Directory",
            "category": "Students",
            "description": "Student master database and dossiers",
            "url": "https://mis.sntcssc.org/admin/students",
            "icon": "users",
            "permission": "students.view"
          }
        ]
      }
    ],
    "quick_actions": [
      {
        "id": "action_toggle_fullscreen",
        "title": "Toggle Full Screen Mode",
        "category": "Quick Actions",
        "action": "toggle-fullscreen",
        "icon": "maximize-2",
        "badge": "F11 / Instant"
      }
    ],
    "total_modules": 16,
    "total_navigation_items": 58
  },
  "meta": {
    "user_id": 1,
    "user_name": "Super Administrator",
    "role": "Super Administrator"
  }
}
```

---

### 3.3 Inspect Viewport Display Preferences
**Endpoint:** `GET /api/v1/search/preferences`  
**Required Scope:** `read:search`  
**Description:** Returns current viewport display configuration (fullscreen vs normal) and search preferences.

#### Sample Response (200 OK)
```json
{
  "success": true,
  "data": {
    "effective_fullscreen": "fullscreen",
    "user_default_fullscreen": "fullscreen",
    "system_default_fullscreen": "normal",
    "search_history_enabled": true,
    "quick_actions_enabled": true
  }
}
```

---

### 3.4 Update Viewport Display & Search Preferences
**Endpoint:** `POST /api/v1/search/preferences`  
**Required Scope:** `manage:search`  
**Request Payload (JSON):**
```json
{
  "default_fullscreen": "fullscreen",
  "search_history_enabled": true,
  "quick_actions_enabled": true,
  "apply_system_wide": false
}
```

#### Sample Response (200 OK)
```json
{
  "success": true,
  "data": {
    "effective_fullscreen": "fullscreen",
    "user_default_fullscreen": "fullscreen",
    "system_default_fullscreen": "normal",
    "search_history_enabled": true,
    "quick_actions_enabled": true
  },
  "meta": {
    "message": "Viewport and search preferences updated successfully."
  }
}
```

---

## 4. Cross-Platform Client Implementations

### Flutter (Dart) — Native Mobile Command Palette Integration
```dart
import 'dart:convert';
import 'package:http/http.dart' as http;

class GlobalSearchService {
  final String baseUrl = 'https://mis.sntcssc.org/api/v1';
  final String apiKey;

  GlobalSearchService({required this.apiKey});

  Future<Map<String, dynamic>> search(String query, {String? category, int limit = 20}) async {
    final uri = Uri.parse('$baseUrl/search/global').replace(queryParameters: {
      'q': query,
      if (category != null) 'category': category,
      'limit': limit.toString(),
    });

    final response = await http.get(uri, headers: {
      'X-API-Key': apiKey,
      'Accept': 'application/json',
    });

    if (response.statusCode == 200) {
      return json.decode(response.body)['data'];
    } else {
      throw Exception('Failed to execute search: ${response.body}');
    }
  }

  Future<void> updateFullscreenPreference(bool fullscreen) async {
    final response = await http.post(
      Uri.parse('$baseUrl/search/preferences'),
      headers: {
        'X-API-Key': apiKey,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: json.encode({
        'default_fullscreen': fullscreen ? 'fullscreen' : 'normal',
      }),
    );

    if (response.statusCode != 200) {
      throw Exception('Failed to update preference: ${response.body}');
    }
  }
}
```

### React Native / Next.js (TypeScript)
```typescript
import axios from 'axios';

const api = axios.create({
  baseURL: 'https://mis.sntcssc.org/api/v1',
  headers: {
    'X-API-Key': 'YOUR_API_KEY',
    'Accept': 'application/json',
  },
});

export async function searchAdmin(query: string, category?: string) {
  const response = await api.get('/search/global', {
    params: { q: query, category, limit: 15 },
  });
  return response.data.data;
}

export async function setViewportPreference(mode: 'normal' | 'fullscreen') {
  const response = await api.post('/search/preferences', {
    default_fullscreen: mode,
  });
  return response.data.data;
}
```

### Electron Desktop Client (Node.js Main Process)
```javascript
const { app, BrowserWindow, globalShortcut } = require('electron');
const axios = require('axios');

let mainWindow;

function createSearchWindow() {
  mainWindow = new BrowserWindow({
    width: 720,
    height: 480,
    frame: false,
    transparent: true,
    webPreferences: { nodeIntegration: true }
  });

  // Global Spotlight hotkey (Command+K or Ctrl+K)
  globalShortcut.register('CommandOrControl+K', () => {
    mainWindow.isVisible() ? mainWindow.hide() : mainWindow.show();
  });
}

async function syncFullscreenSetting(apiKey) {
  try {
    const res = await axios.get('https://mis.sntcssc.org/api/v1/search/preferences', {
      headers: { 'X-API-Key': apiKey }
    });
    if (res.data.data.effective_fullscreen === 'fullscreen') {
      mainWindow.setFullScreen(true);
    }
  } catch (err) {
    console.error('Failed to sync viewport preference:', err);
  }
}
```
