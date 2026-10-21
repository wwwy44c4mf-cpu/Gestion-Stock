BEGIN {split("app web db worker scheduler", required, " "); bad=0}
NF>=2 {
  state[$1]=$2
  if ($1 ~ /^(app|web|db|worker|scheduler|backup|proxy)$/ && ($2!="running" || $3!="healthy")) bad=1
}
END {
  for (i in required) if (!(required[i] in state)) bad=1
  if (require_backup==1 && !("backup" in state)) bad=1
  if (require_proxy==1 && !("proxy" in state)) bad=1
  exit bad
}
