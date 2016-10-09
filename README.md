![](https://raw.githubusercontent.com/catfan/Medoo/develop/src/medoo-logo.png)

## [Medoo](http://medoo.in)

> The Lightest PHP database framework to accelerate development

## Main Features

* **Lightweight** - 20KB around with only one file.

* **Easy** - Extremely easy to learn and use, friendly construction.

* **Powerful** - Support various common and complex SQL queries.

* **Compatible** - Support various SQL database, including MySQL, MSSQL, SQLite, MariaDB, Sybase, Oracle, PostgreSQL and more.

* **Security** - Prevent SQL injection.

* **Free** - Under MIT license, you can use it anywhere if you want.

## Get Started

### Install via composer

Add Medoo to composer.json configuration file.
```
$ composer require catfan/Medoo
```

And update the composer
```
$ composer update
```

```php
// If you installed via composer, just use this code to requrie autoloader on the top of your projects.
require 'vendor/autoload.php';

// Or if you just download the medoo.php into directory, require it with the correct path.
require_once 'medoo.php';

// Initialize
$database = new medoo([
    'database_type' => 'mysql',
    'database_name' => 'name',
    'server' => 'localhost',
    'username' => 'your_username',
    'password' => 'your_password',
    'charset' => 'utf8'
]);

// Enjoy
$database->insert('account', [
    'user_name' => 'foo',
    'email' => 'foo@bar.com',
    'age' => 25,
    'lang' => ['en', 'fr', 'jp', 'cn']
]);
```

新增加了一个list($table,$columns = null, $where = null,$page = 1,$pagesize = 10,$join = null,$setpages = 10,$array = array())方法，返回值数据中带有分页pages、列表list两个项，使用方法基本与select()一致，只是把连表查询放在了后边

另外修改了where_clause($where)方法里AND/OR语句的生成规则，判断是否为空，方便条件搜索（筛选）的写法

```
$where = [];
if($q != '') {$where['filename[~]'] = $q;}
if($starttime != '') {$where['created_at[>]'] = $starttime;}
if($endtime != '') {$where['created_at[<]'] = $endtime;}
$data = $database->list('attrs','*',['ORDER'=>['id'=>'DESC'],'AND'=>$where],$page);

```

## Contribution Guides

For most of time, Medoo is using develop branch for adding feature and fixing bug, and the branch will be merged into master branch while releasing a public version. For contribution, submit your code to the develop branch, and start a pull request into it.

On develop branch, each commits are started with `[fix]`, `[feature]` or `[update]` tag to indicate the change.

Keep it simple and keep it clear.

## License

Medoo is under the MIT license.

## Links

* Official website: [http://medoo.in](http://medoo.in)

* Documentation: [http://medoo.in/doc](http://medoo.in/doc)