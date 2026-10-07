import OrganizationHierarchies from './components/organization-hierarchies';
import type { Props } from './components/organization-types';

export default function Hierarchies(
    props: Pick<
        Props,
        | 'canManage'
        | 'organizations'
        | 'hierarchies'
        | 'purposes'
        | 'operatingUnitTypes'
    >,
) {
    return <OrganizationHierarchies {...props} />;
}
Hierarchies.layout = {
    breadcrumbs: [
        {
            title: 'Hierarki organisasi',
            href: '/settings/organization-hierarchies',
        },
    ],
};
