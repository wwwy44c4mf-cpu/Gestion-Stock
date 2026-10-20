<?php

declare(strict_types=1);
namespace App\Notification;
final class NotificationPayload
{
    public function validate(string $type,array $payload): void
    {
        $fields=match($type) {'stock_low','stock_critical'=>['article','store','quantity','threshold','occurred_at','path'],'information'=>['subject','body','path'],'password_reset'=>['path'],'daily_summary'=>['items'],default=>throw new \InvalidArgumentException('notification_type_invalid')};
        if(array_diff(array_keys($payload),$fields) || array_diff($fields,array_keys($payload))) {throw new \InvalidArgumentException('notification_payload_invalid');}
        foreach($payload as $field=>$value) {
            if(in_array($field,['quantity','threshold'],true)) {if(!is_int($value)||$value<0||$value>9007199254740991) {throw new \InvalidArgumentException('notification_quantity_invalid');}}
            elseif($field==='items') {if(!is_array($value)||!array_is_list($value)||count($value)<1) {throw new \InvalidArgumentException('notification_summary_invalid');}foreach($value as $item) {if(!is_array($item)) {throw new \InvalidArgumentException('notification_summary_invalid');}if(array_key_exists('level',$item)) {if(!in_array($item['level'],['low','critical'],true)) {throw new \InvalidArgumentException('notification_summary_level_invalid');}unset($item['level']);}$this->validate('stock_low',$item);}}
            else {if(!is_string($value)||trim($value)===''||mb_strlen($value,'UTF-8')>($field==='body'?10000:2000)||!preg_match('//u',$value)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)) {throw new \InvalidArgumentException('notification_text_invalid');}
                if(in_array($field,['subject','article','store'],true) && (mb_strlen($value,'UTF-8')>255||strpbrk($value,"\r\n")!==false)) {throw new \InvalidArgumentException('notification_text_invalid');}
                if($field==='path' && (!str_starts_with($value,'/')||str_starts_with($value,'//')||str_contains($value,'\\')||preg_match('/[\r\n]/',$value))) {throw new \InvalidArgumentException('notification_link_invalid');}
                if($field==='occurred_at' && (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D',$value)||strtotime($value)===false)) {throw new \InvalidArgumentException('notification_date_invalid');}
            }
        }
    }
}
