import OrganizationDirectory from './components/organization-directory';
import type { Props } from './components/organization-types';

export default function LegalEntities(
    props: Pick<Props, 'canManage' | 'organizations' | 'operatingUnitTypes'>,
) {
    return (
        <OrganizationDirectory
            {...props}
            classification="legal_entity"
            title="Perusahaan"
        />
    );
}
LegalEntities.layout = {
    breadcrumbs: [{ title: 'Perusahaan', href: '/settings/legal-entities' }],
};
