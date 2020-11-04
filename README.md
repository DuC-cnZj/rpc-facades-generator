# rpc-facades-generator



## Installing

```shell
$ composer require duc_cnzj/rpc-facades-generator
```

## Usage
```shell
rpc-generator your-grpc-file-path
```

## `composer.json` demo
```json
{
  "name": "grpc/grpc-demo",
  "description": "gRPC example for PHP",
  "require": {
    "php": "^7.2",
    "grpc/grpc": "^v1.3.0",
    "google/protobuf": "^v3.3.0"
  },
  "autoload": {
    "psr-4": {
      "Duc\\": "src/Duc"
    }
  },
  "extra": {
    "laravel": {
      "providers": [
        "Duc\\ServiceProvider"
      ]
    }
  },
  "require-dev": {
    "duc_cnzj/rpc-facades-generator": "dev-master"
  }
}
```

## License

MIT