# InitPHP Auth

This library makes logged in user data more organized and easily accessible.

## Features

- Easy to use user permissions manager.
- Ability to use user authorization data on cookies or sessions.
- Ability to write and use your own authorization class.

## Requirements

- PHP 7.4 or later
- [InitPHP ParameterBag Library](https://github.com/InitPHP/ParameterBag)

## Installation

```
composer require initphp/auth
```

## Usage

### Use of Permissions

It is a small but capable library that you can use to define user permissions.

```php
require_once 'vendor/autoload.php';

$perm = new \InitPHP\Auth\Permission([
    'editor',
    'post_list', 'post_edit', 'post_add', 'post_delete'
]);

if($perm->is('editor')){
    // has "editor" authority
    $perm->remove('editor'); // remove "editor" permissions
    $perm->push('user'); // added "user" permission
}
```

**Multiple use :**

```php
/** @var \InitPHP\Auth\Permission $perm */

$perm->is('admin', 'editor'); // True if "admin" or "editor" privileges. Returns false if none of the specified are present.

$perm->remove('admin', 'editor'); // Removes the specified permissions. And returns the actual number of permissions removed.

$perm->push('admin', 'editor'); // Adds the specified permissions. Returns the number of permissions added.
```

### Cookie Adapter

It manages session data on `$_COOKIE` provided by PHP.

```php
require_once 'vendor/autoload.php';
use InitPHP\Auth\Segment;

$auth = Segment::create('authorization', Segment::ADAPTER_COOKIE, [
    'salt'  => 'QO.@zeZiFgSvQd-:' // It is used to verify that the data in this cookie has not changed. Define a unique and secret string of at least 8 characters.
]);
```
### Session Adapter

It manages session data on `$_SESSION` provided by PHP.

```php
session_start();
require_once 'vendor/autoload.php';
use InitPHP\Auth\Segment;

$auth = Segment::create('authorization', Segment::ADAPTER_SESSION);
```

### Write and use your own adapter.

In the example below you can see an example of a simple adapter for basic auth with the help of a database connection.

**_Note :_** The example below is purely for instructional purposes. Using the code below directly will cause serious security vulnerabilities.

```php
namespace App;

class BasicAuthAdapter extends InitPHP\Auth\AbstractAdapter
{
    /** @var \PDO */
    protected $pdo;
    
    protected array $userInfo = [];

    public function __construct(string $name, array $options = [])
    {
        $this->pdo = new \PDO($options['dsn'], $options['username'], $options['password']);
        $statement = $this->pdo->prepare("SELECT * FROM `ùsers` WHERE `user_name` = :user_name AND `password` = :password LIMIT 1");
        $statement->execute([
            ':user_name'    => ($_SERVER['PHP_AUTH_USER'] ?? ''),
            ':password'     => md5(($_SERVER['PHP_AUTH_PW'] ?? ''))
        ]);
        if($statement->rowCount() > 0){
            $this->userInfo = $statement->fetch(\PDO::FETCH_ASSOC);
        }else{
            header("WWW-Authenticate: Basic realm=\"Privare Area\"");
            header("HTTP/1.0 401 Unauthorized");
            echo "Sorry, you need proper credendtials";
            exit;
        }
    }

    public function get(string $key, $default = null)
    {
        return $this->userInfo[$key] ?? $default;
    }

    public function set(string $key, $value): self
    {
        if($key == 'user_name'){
            return $this;
        }
        $statement = $this->pdo->query("UPDATE `ùsers` SET `" . $key . "` = '" . (string)$value . "' WHERE `ùser_name` = " . $this->userInfo['user_name']);
        if($statement !== FALSE){
            unset($this->userInfo[$key]);
        }
        return $this;
    }

    public function collective(array $data): self
    {
        if(isset($data['user_name'])){
            unset($data['user_name']);
        }
        if(empty($data)){
            return $this;
        }
        $sql = "UPDATE `ùsers` SET";
        foreach ($data as $key => $value) {
            $sql .= " `" . $key . "` = '" . $value . "'";
        }
        $sql .= " WHERE `ùser_name` = '" . $this->userInfo['user_name'] . "'";
        if($this->pdo->query($sql) !== FALSE){
            $this->userInfo = array_merge($this->userInfo, $data);
        }
        return $this;
    }
    
    public function has(string $key): bool
    {
        return isset($this->userInfo[$key]);
    }

    public function remove(string ...$key): self
    {
        foreach ($key as $name) {
            if($key == 'user_name'){
                continue;
            }
            if(isset($this->userInfo[$key])){
                $this->userInfo[$key];
                $this->pdo->query("UPDATE `ùsers` SET `" . $key . "` = NULL WHERE `ùser_name` = '".$this->userInfo['user_name']."'");
            }
        }
        return $this;
    }

    public function destroy(): bool
    {
        $this->userInfo = [];
        return true;
    }

}
```

```php
$segment = new \InitPHP\Auth\Segment('', \App\BasicAuthAdapter::class, [
    'dsn'       => 'mysqli:host=localhost;dbname=test_database;charset=utf8mb4',
    'username'  => 'root',
    'password'  => ''
]);
```

## Credits

- [Muhammet ŞAFAK](https://github.com/muhammetsafak) <<info@muhammetsafak.com.tr>>

## License

Copyright &copy; 2022 [MIT License](./LICENSE) 
