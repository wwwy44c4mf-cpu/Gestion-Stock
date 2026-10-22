"""Publish aggregate metrics only; never copy fixture identities or raw logs."""
import json
from pathlib import Path

files = sorted(Path('var/qa').glob('*/performance.json'))
if not files:
    print('Recette interrompue avant la production des mesures. Consulter les etapes en echec.')
else:
    report = json.loads(files[-1].read_text())
    print('## Performances — cinq sessions simultanees\n')
    print('| Parcours | Mesures | p95 (ms) | Cible (ms) | Conforme |')
    print('|---|---:|---:|---:|---|')
    for name, row in report['endpoints'].items():
        print(f"| {name} | {row['samples']} | {row['p95_ms']} | < {row['limit_ms']} | {'Oui' if row['passed'] else 'Non'} |")
    print('\nMesures internes Docker, PHP en mode production, donnees fictives ; elles ne remplacent pas une mesure sur le serveur final.')
