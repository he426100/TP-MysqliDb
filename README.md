# TP-MysqliDb
MysqliDb &amp; thinkphp

让dbObject用起来更像是thinkphp的orm
示例代码：
```php
$this->field('user_pay.*,payer.username,receiver.username')->with('receiver')->with('payer')->where($where)->limit($offset, $limit)->order($orderSort)->select();
```
