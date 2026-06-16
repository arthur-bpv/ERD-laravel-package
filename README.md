<p align="center">
  <a href="https://laravel.com" target="_blank">
    <img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo">
  </a>
</p>

<p align="center">
  <a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
  <a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
  <a href="https://packagist.org/packages/livewire/livewire"><img src="https://img.shields.io/packagist/v/livewire/livewire?label=livewire" alt="Livewire Version"></a>
  <a href="https://packagist.org/packages/getartisanflow/wireflow"><img src="https://img.shields.io/packagist/v/getartisanflow/wireflow?label=wireflow&include_prereleases" alt="WireFlow Version"></a>
  <a href="https://opensource.org/licenses/MIT"><img src="https://img.shields.io/badge/license-MIT-green" alt="License"></a>
</p>

---

## Sobre o Projeto

Aplicação Laravel 12 com [Livewire 4](https://livewire.laravel.com/) e [WireFlow](https://packagist.org/packages/getartisanflow/wireflow) — componentes interativos para diagramas de fluxo baseados em AlpineFlow.

| Camada | Tecnologia |
|---|---|
| Backend | PHP 8.3 + Laravel 12 |
| Frontend reativo | Livewire 4.x |
| Diagramas de fluxo | WireFlow (getartisanflow/wireflow) |
| Build de assets | Vite + Node.js 22 |
| Banco de dados | MySQL 8 / SQLite |

---

## Requisitos

| Ferramenta | Versão mínima |
|---|---|
| PHP | **8.3** |
| Composer | 2.x |
| Node.js | 22.x |
| MySQL | 8.0 (ou SQLite) |

> **Atenção:** PHP 8.3 é obrigatório — o pacote `getartisanflow/wireflow` exige `^8.3`.

---

## Instalação

```bash
git clone <url-do-repositorio>
cd ERD-laravel-package

# Dependências PHP (inclui Livewire e WireFlow)
composer install

# Configurar ambiente
cp .env.example .env
php artisan key:generate

# Banco de dados
php artisan migrate

# Dependências Node e build de assets
npm install
npm run build
```

### Subir o servidor de desenvolvimento

```bash
# Inicia servidor PHP + queue + logs + Vite em paralelo
composer run dev
```

Acesse em: **http://localhost:8000**

Rotas disponíveis:

| Rota | Descrição |
|---|---|
| `/organograma` | Organograma interativo (componente Livewire `Organograma` com WireFlow) |
| `/hello` | Rota de teste — retorna `Hello World` |

> No IDX, o preview web é servido automaticamente via `php artisan serve`; abra `/organograma` no painel de preview para validar o setup.

---

## Pacotes instalados

### Livewire 4.x

Já incluso em `composer.json`. Para adicionar a um projeto Laravel existente:

```bash
composer require livewire/livewire:^4.0
```

### WireFlow (ArtisanFlow)

> Componentes de diagramas de fluxo interativos para Livewire.  
> Pacote em fase **alpha** — `minimum-stability: alpha` já está configurado no `composer.json`.

Já incluso em `composer.json`. Para adicionar a um projeto Laravel existente:

```bash
composer require getartisanflow/wireflow
php artisan wireflow:install
```

---

## Firebase Studio / Project IDX

O ambiente IDX usa Nix e está configurado em `.idx/dev.nix` com PHP 8.3.

Ao abrir o workspace pela **primeira vez**, roda automaticamente:

1. `composer install` + `npm install`
2. Cópia do `.env.example` → `.env` + geração do `APP_KEY`
3. Migrations com `php artisan migrate`
4. Build dos assets com `npm run build`

O preview web fica disponível no painel do IDX, servido via `php artisan serve`.

A cada **restart** do workspace, `composer install` roda automaticamente para manter as dependências sincronizadas.

---

## Docker (opcional)

Os arquivos `Dockerfile` e `docker-compose.yml` estão disponíveis para quem preferir ambiente containerizado.

```bash
docker compose up --build
```

> Na primeira execução, rodar `composer update` antes para sincronizar o lock file:
> ```bash
> docker compose run --rm --entrypoint="" app composer update
> docker compose up -d
> ```

---

## Testes

```bash
composer run test
# ou diretamente
php artisan test
```

---

## Estrutura de pacotes

```
composer.json
├── laravel/framework        ^12.0   — framework base
├── livewire/livewire        ^4.3    — reatividade server-side
├── getartisanflow/wireflow  ^0.1    — diagramas de fluxo interativos
└── laravel/tinker           ^2.10   — REPL para debugging
```

---

## Licença

Distribuído sob a licença [MIT](https://opensource.org/licenses/MIT).
