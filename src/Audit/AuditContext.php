<?php

declare(strict_types=1);
namespace App\Audit;

/** Positive, typed projection: unknown context keys and arbitrary text never reach the reader. */
final class AuditContext
{
    public function project(string $action, array $context): \stdClass
    {
        $out=[];
        if (str_starts_with($action,'user.')) { $out=$this->fields($context,'changed_fields',['name','email','role','store_ids','active']); }
        if (preg_match('/^(store|article|category|proposal)\./D',$action)) {
            $out=$this->fields($context,'fields',['code','name','description','address','active','sku','category_id','unit_code','parent_id','version','status','operation','article_id','base_version','payload','reviewed_by','reviewed_at','review_comment','published_article_id']);
            if(isset($context['cascade_from'])&&$this->uuid($context['cascade_from'])) {$out['cascade_from']=$context['cascade_from'];}
        }
        if (str_starts_with($action,'stock.')) {
            if(isset($context['type'])&&in_array($context['type'],['entry','consumption','transfer','adjustment','return','reversal'],true)) {$out['type']=$context['type'];}
            foreach(['original_id','consumption_id','inventory_id','return_group_id'] as $key) {if(isset($context[$key])&&$this->uuid($context[$key])) {$out[$key]=$context[$key];}}
            if(isset($context['delta'])&&is_int($context['delta'])&&abs($context['delta'])<=9007199254740991) {$out['delta']=$context['delta'];}
            if($action==='stock.thresholds_updated') {foreach(['before','after'] as $key) {if(isset($context[$key])&&is_array($context[$key])) {$out[$key]=(object)$this->quantities($context[$key],['low_threshold','critical_threshold']);}}}
        }
        if(str_starts_with($action,'inventory.')) {
            if(isset($context['status'])&&in_array($context['status'],['draft','in_progress','pending_validation','closed','cancelled'],true)) {$out['status']=$context['status'];}
            if(isset($context['adjustment_ids'])&&is_array($context['adjustment_ids'])) {$out['adjustment_ids']=array_values(array_filter($context['adjustment_ids'],$this->uuid(...)));}
            if(isset($context['lines'])&&is_array($context['lines'])) {
                $out['lines']=[];foreach($context['lines'] as $line) {if(is_array($line)&&isset($line['article_id'])&&$this->uuid($line['article_id'])) {$out['lines'][]=['article_id'=>$line['article_id'],...$this->quantities($line,['previous_qty','counted_qty','previous_version','version'])];}}
            }
        }
        if($action==='alert.recipients_updated') {
            // No contact information or account identifiers in the reader's summary.
            foreach(['before','after'] as $key) {if(isset($context[$key])&&is_array($context[$key])) {$c=$context[$key];$out[$key]=(object)['use_defaults'=>is_bool($c['use_defaults']??null)?$c['use_defaults']:null,'recipient_count'=>is_array($c['recipients']??null)?count($c['recipients']):0];}}
        }
        if(in_array($action,['information.queued','notifications.daily_summary'],true)) {
            foreach(['recipient_count','item_count'] as $key) {if(isset($context[$key])&&is_int($context[$key])&&$context[$key]>=0) {$out[$key]=$context[$key];}}
            if($action==='notifications.daily_summary'&&isset($context['local_date'])&&is_string($context['local_date'])&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$context['local_date'])) {$out['local_date']=$context['local_date'];}
        }
        // Authentication correlation hashes, message fingerprints and unknown payloads stay private.
        return (object)$out;
    }
    private function fields(array $c,string $key,array $allowed): array {return isset($c[$key])&&is_array($c[$key])?[$key=>array_values(array_filter($c[$key],static fn($v)=>is_string($v)&&in_array($v,$allowed,true)))]:[];}
    private function quantities(array $c,array $keys): array {$out=[];foreach($keys as $key) {if(array_key_exists($key,$c)&&($c[$key]===null||(is_int($c[$key])&&$c[$key]>=0&&$c[$key]<=9007199254740991))) {$out[$key]=$c[$key];}}return $out;}
    private function uuid(mixed $v): bool {return is_string($v)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di',$v)===1;}
}
