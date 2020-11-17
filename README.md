# garoon-schedule-hoster

雑にGaroon APIのJSONを受け付けてホストするアプリケーションです。
参照カレンダーとして利用することを想定しています。

## スケジュールの保存

`POST` または `PUT` メソッドでスケジュールを保存します。

保存できるデータは [Garoon REST API で取得した予定の一覧取得](https://developer.cybozu.io/hc/ja/articles/360000440583#step2) のレスポンスデータのみです。

保存用の名前( `name` )をクエリパラメータにて指定します。

※ 公開URLに設置する場合、暗号化通信を利用した上で、推測不可能な `name` を指定することを推奨します。

```sh
curl -d @data/example.json -X PUT 'https://example.com/?name=test'
```

## スケジュールの取得

`GET` メソッドで保存したスケジュールを取得します。

取得する名前( `name` )、拡張子( `ext` )をクエリパラメータで指定します。

利用可能な拡張子は `ics` `json` のみです。

拡張子 `ics` にて `url` パラメータを指定した場合、イベントに指定されたURLをベースとしたイベントURLを付与します。

```sh
curl 'https://example.com/?name=test&ext=ics&url=https://example.com/scripts/grn.exe'
```

## Query Parameter

| Name | Summary | Describe
| :- | :- | :-
| name | スケジュール名 | 取得するスケジュール名(ID)
| ext | 拡張子 | 取得する拡張子 (ics/jsonのみ)
| url | ベースURL | イベントURL生成用ベースURL

## Files

| Name | Describe
| :- | :-
| data/ | データを保存するディレクトリです。書き込み権限が必要です。
| index.php | 本アプリケーションです。単一のPHPファイルで構成されています。

