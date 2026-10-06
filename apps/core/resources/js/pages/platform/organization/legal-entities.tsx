import OrganizationDirectory from './components/organization-directory';
import type { Props } from './components/organization-types';

export default function LegalEntities(
    props: Pick<Props, 'canManage' | 'organizations' | 'operatingUnitTypes'>,
) {
    return (
        <OrganizationDirectory
            {...props}
            classification="legal_entity"
            title="Entitas legal"
        />
    );
}
LegalEntities.layout = {
    breadcrumbs: [{ title: 'Entitas legal', href: '/settings/legal-entities' }],
};
