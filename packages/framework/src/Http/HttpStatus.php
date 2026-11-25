<?php

declare(strict_types=1);

namespace Atom\Http;

enum HttpStatus: int
{
    case Ok = 200;
    case Created = 201;
    case NoContent = 204;
    case MovedPermanently = 301;
    case Found = 302;
    case BadRequest = 400;
    case Unauthorized = 401;
    case Forbidden = 403;
    case NotFound = 404;
    case MethodNotAllowed = 405;
    case Conflict = 409;
    case ContentTooLarge = 413;
    case UnprocessableContent = 422;
    case TooManyRequests = 429;
    case InternalServerError = 500;

    public function reasonPhrase(): string
    {
        return match ($this) {
            self::Ok => "OK",
            self::Created => "Created",
            self::NoContent => "No Content",
            self::MovedPermanently => "Moved Permanently",
            self::Found => "Found",
            self::BadRequest => "Bad Request",
            self::Unauthorized => "Unauthorized",
            self::Forbidden => "Forbidden",
            self::NotFound => "Not Found",
            self::MethodNotAllowed => "Method Not Allowed",
            self::Conflict => "Conflict",
            self::ContentTooLarge => "Content Too Large",
            self::UnprocessableContent => "Unprocessable Content",
            self::TooManyRequests => "Too Many Requests",
            self::InternalServerError => "Internal Server Error",
        };
    }
}
