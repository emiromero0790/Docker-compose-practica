# Control de incidencias con Docker Compose

Práctica individual de Infraestructura de Servicios. Autor: [tu nombre].

## Arquitectura
| Servicio | Tecnología | Puerto |
|---|---|---|
| frontend | Nginx 1.27 + HTML/CSS/JS | 8081 (público) |
| backend | PHP 8.3 + Apache | 80 (solo red interna) |
| db | MariaDB 11.4 | 3306 (solo red interna) |
| redis | Redis 7.4 | 6379 (solo red interna) |
| mailpit | Mailpit | 8025 UI pública, 1025 SMTP interno |

Nota: MariaDB usa el puerto 3306 y el directorio /var/lib/mysql
(el enunciado indicaba 5432 y otra ruta, que corresponden a PostgreSQL).

## Requisitos
Docker con Docker Compose v2.

## Ejecución
```bash
cp .env.example .env      
docker compose up -d --build
```
- Aplicación: http://localhost:8081
- Mailpit: http://localhost:8025 (solo desarrollo; no entrega correo real)

## Endpoints
GET /api/health · GET /api/incidencias · POST /api/incidencias ·
PUT /api/incidencias/{id} · DELETE /api/incidencias/{id}

## Mejora individual
Prioridad (baja, media, alta) guardada en MariaDB y mostrada con colores.# Docker-compose-practica
