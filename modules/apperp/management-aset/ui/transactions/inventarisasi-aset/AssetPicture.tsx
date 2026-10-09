import { RecordPictures } from '@/components/record-pictures';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';

/** Isi Detail pada FactBox aset ditentukan halaman aset, bukan oleh FactBox bersama. */
export default function AssetPicture({ assetId }: { assetId: string }) {
    return (
        <CollapsibleSectionGroup defaultValue={['picture']}>
            <CollapsibleSection value="picture" title="Foto aset">
                <RecordPictures
                    key={assetId}
                    recordType="aset_tr_aset"
                    recordId={assetId}
                />
            </CollapsibleSection>
        </CollapsibleSectionGroup>
    );
}
