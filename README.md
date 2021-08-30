# garoon-schedule-server

雑にGaroon REST APIで取得した予定のJSONをPOSTして保存し、iCalendar形式でGETできるアプリケーションです。

WebHookでうけつけたデータを参照カレンダーとして利用することを想定しています。

## スケジュールの保存

`POST` または `PUT` メソッドでスケジュールを保存します。

POST(PUT)できるデータは [Garoon REST API で取得した予定の一覧取得](https://developer.cybozu.io/hc/ja/articles/360000440583#step2) のレスポンスデータのみです。

保存用の名前( `name` )をクエリパラメータにて指定します。

暗号化通信を利用した上で、推測不可能なパスワードを設定したベーシック認証でアクセスする必要があります。

※ すでに同一の `name` で保存されている場合、認証情報が一致しない場合保存することができません。(サーバにアクセスし、直接該当ファイルを削除してください)

```sh
curl 'https://example.com/?name=test' -X PUT -d @data/example.json -H 'Authorization: Basic dXNlcjpwYXNzd29yZA=='
```

## スケジュールの取得

`GET` メソッドで保存したスケジュールを取得します。

取得する名前( `name` )、拡張子( `ext` )をクエリパラメータで指定します。

利用可能な拡張子は `ics` `json` のみです。

拡張子 `ics` にて `url` パラメータを指定した場合、イベントに指定されたURLをベースとしたイベントURLを付与します。

※ 保存時の `name` 及び認証情報が一致しない場合取得することができません。

```sh
curl 'https://example.com/?name=test&ext=ics&url=https://example.com/scripts/grn.exe' -H 'Authorization: Basic dXNlcjpwYXNzd29yZA=='
```

## Query Parameter

| 名前 | 種別 | 必須 | 説明 | 備考
| :- | :- | :- | :- | :-
| name| クエリ | yes | 取得するスケジュール名 | 半角英数およびハイフン
| ext | クエリ | no | 取得する拡張子 | ics / json / txtのみ (省略時はics)
| url | クエリ | no  | イベントURL生成用ベースURL | 設定されている場合、LOCATIONを設定します
| alarm | クエリ | no | 通知したい時間を秒で指定 | VALARMを設定します。<br />カンマ区切りで複数指定可能
| max-attendees | クエリ | no | 予定本文に含める参加者名の最大値 | 初期値: 20
| skip-keywords | クエリ | no | SUMMARYに一致するキーワードが含まれる場合予定として取得しません | カンマ区切りで複数指定可能
| Authorization | ヘッダ | yes | 認証ユーザ・パスワード | ベーシック認証
| AUTH_IV | サーバ環境変数 | no | 初期化ベクトル | 16進数文字列

## Files

| Name | Describe
| :- | :-
| data/ | データを保存するディレクトリです。書き込み権限が必要です。
| index.php | 本アプリケーションです。単一のPHPファイルで構成されています。
