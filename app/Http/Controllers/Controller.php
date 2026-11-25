<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.1.0',
    title: 'PINTU MADRASAH — Open API Gateway & Master Data Hub',
    description: 'Spesifikasi RESTful API v1 untuk ekosistem aplikasi satelit madrasah (SIPSMAD, PTSP, E-Surat, RDM, E-Learning). Mendukung API Key berbasis hash SHA-256, kontrol scope granular, X-Request-Id, seleksi kolom (?fields=), rekonsiliasi inkremental (?updated_since=), dan Transactional Outbox Webhook HMAC-SHA256 v2.',
    contact: new OA\Contact(email: 'admin@madrasah.id')
)]
#[OA\Server(
    url: '/',
    description: 'PINTU MADRASAH API Server'
)]
#[OA\SecurityScheme(
    securityScheme: 'ApiKeyAuth',
    type: 'apiKey',
    in: 'header',
    name: 'X-API-KEY',
    description: 'Kunci API per-klien berformat pm_live_<prefix8>_<secret40>. Scope: students:read, students:identifiers, students:pii, staff:read, staff:pii, classes:read, academic-years:read.'
)]
#[OA\SecurityScheme(
    securityScheme: 'IntegrationTokenAuth',
    type: 'apiKey',
    in: 'header',
    name: 'X-Integration-Token',
    description: 'Token kredensial integrasi bookmarklet EMIS berformat pm_int_<prefix8>_<secret40> (purpose: emis_siswa | emis_gtk).'
)]
#[OA\Schema(
    schema: 'ApiMeta',
    properties: [
        new OA\Property(property: 'request_id', type: 'string', example: '2c5f9b80-4f1a-4b2c-9a11-0123456789ab'),
        new OA\Property(property: 'generated_at', type: 'string', format: 'date-time', example: '2026-11-23T10:00:00Z'),
    ]
)]
#[OA\Schema(
    schema: 'ApiError',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'message', type: 'string', example: "Scope 'students:read' tidak dimiliki API Key ini."),
        new OA\Property(
            property: 'error',
            type: 'object',
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'FORBIDDEN_SCOPE'),
            ]
        ),
        new OA\Property(property: 'request_id', type: 'string', example: '2c5f9b80-4f1a-4b2c-9a11-0123456789ab'),
    ]
)]
abstract class Controller
{
    //
}
