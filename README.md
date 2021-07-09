# garoon-schedule-server

雑にGaroon REST APIで取得した予定のJSONをPOSTして保存し、iCalendar形式でGETできるアプリケーションです。

WebHookでうけつけたデータを参照カレンダーとして利用することを想定しています。

## スケジュールの保存

`POST` または `PUT` メソッドでスケジュールを保存します。

保存できるデータは [Garoon REST API で取得した予定の一覧取得](https://developer.cybozu.io/hc/ja/articles/360000440583#step2) のレスポンスデータのみです。

保存用の名前( `name` )をクエリパラメータにて指定します。

※ 暗号化通信を利用した上で、推測不可能な `name` および `secret` を指定することを推奨します。

```sh
curl -d @data/example.json -X PUT 'https://example.com/?name=test&secret=hoge'
```

## スケジュールの取得

`GET` メソッドで保存したスケジュールを取得します。

取得する名前( `name` )、拡張子( `ext` )をクエリパラメータで指定します。

利用可能な拡張子は `ics` `json` のみです。

拡張子 `ics` にて `url` パラメータを指定した場合、イベントに指定されたURLをベースとしたイベントURLを付与します。

※ 保存時の `name` `secret` `iv` が一致しない場合取得することができません。

```sh
curl 'https://example.com/?name=test&secret=hoge&ext=ics&url=https://example.com/scripts/grn.exe'
```

## Query Parameter

| Name | Summary | Describe
| :- | :- | :-
| name | スケジュール名 | 取得するスケジュール名(ID)
| ext | 拡張子 | 取得する拡張子 (ics/jsonのみ)
| url | ベースURL | イベントURL生成用ベースURL (LOCATIONを設定します)
| secret | 暗号キー | 指定された場合、保存・取得時に暗号化/復号化します
| iv | 初期化ベクトル | 16進数文字列 ( `secret` 指定時のみ有効)
| alarm | 通知時間 | 通知したい時間(N秒前)を秒で指定 (VALARMを設定します。カンマ区切りで複数指定可能です)
| max-attendees | 参加者名を取得する最大値 | 指定以上の参加者が存在する場合、予定本文に含めず省略します (初期値: 20)
| skip-keywords | 取得しないキーワード | SUMMARYに一致するキーワードが含まれる場合予定として取得しません (カンマ区切りで複数指定可能です)

## Files

| Name | Describe
| :- | :-
| data/ | データを保存するディレクトリです。書き込み権限が必要です。
| index.php | 本アプリケーションです。単一のPHPファイルで構成されています。
