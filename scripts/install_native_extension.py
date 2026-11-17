#!/usr/bin/env python3
"""Install the native extension into a selected MetaForge checkout; never reads .env or changes a database."""
import argparse
import hashlib
from pathlib import Path
import os

p=argparse.ArgumentParser(description=__doc__)
p.add_argument('metaforge_root',type=Path)
p.add_argument('--update',action='store_true',help='Back up and replace differing extension/resource files')
a=p.parse_args();target=a.metaforge_root.resolve();repo=Path(__file__).resolve().parents[1]
config=target/'app/Config/MetaForge.php';routes=target/'app/Config/Routes.php'
if not (target/'spark').is_file() or not config.is_file() or not routes.is_file():p.error('Select a MetaForge source checkout')
source=config.read_text();registration='        \\App\\Libraries\\CementIntegration\\CatalogService::register($registry);\n';anchor='        return $registry;'
if registration not in source and source.count(anchor)!=1:p.error('Unrecognized service registry configuration; no files changed')
route="$routes->get('api/cement/capabilities', 'CementCapabilities::index');"
route_source=routes.read_text()
writes={path.relative_to(repo/'integrations/metaforge'):path.read_bytes() for path in (repo/'integrations/metaforge').rglob('*.php')}
for name in ('recordtypes.json','semantic_field_registry.json','shared_recordtypes.json','shared_semantic_field_registry.json'):
    writes[Path('resources/cement')/name]=(repo/'resources/cement'/name).read_bytes()
# Upgrade only the recognized native executor seam; normal HTTP plans still reject these private keys.
executor=target/'app/Libraries/MetaForge/Mutation/RecordExecutor.php'
executor_source=executor.read_text()
old="""        foreach ($response[MutationKeys::SERVICE_MUTATION_PLANS] as $plan) {
            $this->execute($plan, $context, $depth + 1, $lineage);
        }"""
new=r"""        \App\Libraries\CementIntegration\TrustedPlans::apply($response,
            fn(array $plan): array => $this->execute($plan, $context, $depth + 1, $lineage));"""
if new not in executor_source:
    if executor_source.count(old)!=1 or executor_source.count('        array $response,')!=1:p.error('Unrecognized native plan executor; no files changed')
    writes[executor.relative_to(target)]=executor_source.replace(old,new).replace('        array $response,','        array &$response,').encode()
# Native subscription bindings must publish the operation already supplied to trigger evaluation.
prepared=writes.get(executor.relative_to(target),executor_source.encode()).decode()
binding_anchor="""                    DatabaseKeys::RECORD_STORE => [DatabaseKeys::RECORD_ID => $id],
                ],
            );"""
binding_new="""                    DatabaseKeys::RECORD_STORE => [DatabaseKeys::RECORD_ID => $id],
                    DefinitionKeys::RT_RELATED_OPERATION => $operation,
                ],
            );"""
if binding_new not in prepared:
    if prepared.count(binding_anchor)!=1:p.error('Unrecognized native subscription bindings; no files changed')
    writes[executor.relative_to(target)]=prepared.replace(binding_anchor,binding_new).encode()
# Preflight every extension/resource before modifying any file.
for relative,data in writes.items():
    dest=target/relative
    if dest.is_symlink():p.error('Refusing a symlink target: '+str(relative))
    if dest.exists() and dest.read_bytes()!=data and relative!=executor.relative_to(target) and not a.update:p.error('Existing file differs; review it or use --update for a backed-up replacement: '+str(relative))
if registration not in source:writes[config.relative_to(target)]=source.replace(anchor,registration+anchor).encode()
if route not in route_source:writes[routes.relative_to(target)]=(route_source+'\n'+route+'\n').encode()
file_routes=["$routes->get('api/cement/customer-statement', 'CementQueries::statement');","$routes->post('api/cement/files', 'CementFiles::handle', ['filter' => 'csrf']);","$routes->get('api/cement/files/(:segment)', 'CementFiles::handle/$1');"]
prepared_routes=writes.get(routes.relative_to(target),route_source.encode()).decode()
for file_route in file_routes:
    if file_route not in prepared_routes:prepared_routes+='\n'+file_route+'\n'
if prepared_routes!=route_source:writes[routes.relative_to(target)]=prepared_routes.encode()
for relative,data in writes.items():
    dest=target/relative
    if dest.is_symlink():p.error('Refusing a symlink target: '+str(relative))
    if dest.exists():
        old=dest.read_bytes()
        if old==data:continue
        backup=dest.with_name(dest.name+'.cement-backup-'+hashlib.sha256(old).hexdigest()[:12])
        if backup.exists() and backup.read_bytes()!=old:p.error('Backup collision; no replacement performed')
        backup.write_bytes(old)
    dest.parent.mkdir(parents=True,exist_ok=True)
    temporary=dest.with_name(dest.name+'.cement-install-tmp');temporary.write_bytes(data);os.replace(temporary,dest)
print('Extension installed. Review source changes, then run php spark cement:install-native explicitly.')
