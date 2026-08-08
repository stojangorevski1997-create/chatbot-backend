# Chatbot Backend — PHP API

Minimal OpenAI-compatible chat endpoint for the [chatbot-widget](https://github.com/YOUR_USERNAME/chatbot-widget).

## Features

- **Groq primary** (free tier, ~14k req/day, sub-second latency)
- **OpenRouter fallback** (auto-discovers free models)
- `/debug` endpoint for diagnostics
- Zero dependencies (no Composer, no Laravel)
- CORS-enabled for any frontend

## Endpoints

| Method | Path     | Purpose                                      |
|--------|----------|----------------------------------------------|
| GET    | `/debug` | PHP/cURL/env/provider diagnostics            |
| POST   | `/`      | Chat completion (OpenAI-compatible payload)  |

## Setup (local)

```bash
cp .env.example .env
# Edit .env — add your GROQ_API_KEY (and/or OPENROUTER_API_KEY)

php -S localhost:8000 api.php
```

## Setup (Render.com)

1. New Web Service → connect this repo
2. **Build command**: *(empty)*
3. **Start command**: `php -S 0.0.0.0:$PORT api.php`
4. Add environment variables: `GROQ_API_KEY`, optionally `OPENROUTER_API_KEY`
5. Deploy

## Tech

- Vanilla PHP 8.2+
- cURL for HTTP
- OpenAI-compatible request format (works with Groq, OpenRouter, OpenAI, etc.)

## Author

Stojan Gjorevski — Brainster Academy Full-Stack graduate. Available for hire on [Upwork](https://upwork.com).