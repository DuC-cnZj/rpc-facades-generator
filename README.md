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

## proto demo
```proto
syntax="proto3";

// {package, php_metadata_namespace} required;
package duc.dm;
option go_package="duc/dm";
option php_metadata_namespace = "Duc\\DM";

message DM {
    int64 ID = 1;
    int32 Type = 2;
    string Content = 3;
}

message Response {
    int32 code  =1;
    string data = 2; 
}

service DMController {
    rpc Create (DM) returns (Response);
}
```

## License

MIT