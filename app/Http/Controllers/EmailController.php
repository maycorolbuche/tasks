<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class EmailController extends Controller
{
    public function sendEmail(Request $request)
    {
        Mail::send([], [], function ($message) use ($request) {
            $message->to($request->input('to'))
                ->subject($request->input('subject'))
                ->setBody($request->input('message'), 'text/html');
        });

        return response()->json(['message' => 'E-mail enviado com sucesso'], 200);
    }
}
