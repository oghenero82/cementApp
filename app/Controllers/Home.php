<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string
    {
        $this->response->setHeader('X-Content-Type-Options', 'nosniff')->setHeader('Referrer-Policy', 'same-origin')->setHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
        return view('cement');
    }
}
