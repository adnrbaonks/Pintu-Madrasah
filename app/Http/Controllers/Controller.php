<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: "1.0.0",
    title: "PINTU MADRASAH Open API Gateway",
    description: "Dokumentasi RESTful API untuk SIPSMAD dan Portal Eksternal",
    contact: new OA\Contact(email: "admin@madrasah.id")
)]
#[OA\Server(
    url: "/",
    description: "API Server"
)]
abstract class Controller
{
    //
}
