# SDKのインストール

要件: PHP 7.1.0 以降

Composer を使用して API クライアントをインストールするには、composer.json ファイルに以下を追加します。
```
{
"require":
  {
    "bitmovin/bitmovin-api-sdk-php": "*"
  }
}
```
次に php composer.phar install を実行します。

または

次のコマンドを実行します

> php composer.phar require bitmovin/bitmovin-api-sdk-php

SDK を更新する際は次のコマンドを実行します。

> php composer.phar update bitmovin/bitmovin-api-sdk-php