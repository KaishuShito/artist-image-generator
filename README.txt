=== Artist Image Generator - AI画像生成プラグイン for Wordpress ===
Contributors: Kaishu Shito
Requires at least: 5.3
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.12
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

OpenAI DALL·E 2/3を使用してAI画像を作成し、既存の画像を編集します。AIを使ってユーザーに力を与えます：WPプロフィールアバターを作成し、公開フォームを使ってトピックを通じて画像を生成します。

== 説明 ==

Artist Image Generatorは、OpenAI DALL·E 2とDALL·E 3に基づく強力なWordPressプラグインで、AIを使用して画像を作成し、編集することができます。このプラグインは、2つの主要な機能を提供します：

- **画像生成**: テキストプロンプトに基づいて、オリジナルの画像を一から作成します。一度に1-10枚の画像を、256x256、512x512、または1024x1024ピクセルのサイズでリクエストすることができます。
- **ショートコード**: ユーザーが画像を生成し、ダウンロードするための複数選択フォームをショートコードで生成します

### Credits

- [OpenAI GPT-3 Api Client in PHP](https://github.com/orhanerday/open-ai)
- [OpenAI - DALL·E 2](https://openai.com/dall-e-2/)
- [Pierre Viéville](https://www.pierrevieville.fr/)
- [Pierre Viéville's blog](https://developpeur-web.site/)

### プラグインの設定

このプラグインは、OpenAIが提供するサービスの一部である**DALL·E 2/3**を使用しています。これを使用するには、**OpenAI APIキーを生成**する必要があります。

プラグインの"設定"タブに移動すると、OpenAI APIキーを作成するためのすべての指示が表示されます：

- OpenAI開発者ポータルにサインアップ/ログインします：[https://openai.com/api/](https://openai.com/api/)
- **ユーザー > APIキーを表示、新しいシークレットキーを作成**します：[https://platform.openai.com/account/api-keys](https://platform.openai.com/account/api-keys)
- 新しいシークレットキーを**OPENAI_API_KEY**フィールドにコピーして貼り付けます。
- "変更を保存"を押して、**プラグインの使用を開始します**。