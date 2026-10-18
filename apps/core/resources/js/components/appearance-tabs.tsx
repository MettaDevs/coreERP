import { ToggleGroup, ToggleGroupItem } from '@apperp/ui/toggle-group';
import { Monitor, Moon, Sun } from 'lucide-react';
import { useAppearance } from '@/hooks/use-appearance';

export default function AppearanceToggleTab() {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <ToggleGroup
            type="single"
            variant="outline"
            value={appearance}
            aria-label="Tema tampilan"
            className="flex-wrap"
            onValueChange={(value) => {
                if (
                    value === 'light' ||
                    value === 'dark' ||
                    value === 'system'
                ) {
                    updateAppearance(value);
                }
            }}
        >
            <ToggleGroupItem value="light">
                <Sun />
                Terang
            </ToggleGroupItem>
            <ToggleGroupItem value="dark">
                <Moon />
                Gelap
            </ToggleGroupItem>
            <ToggleGroupItem value="system">
                <Monitor />
                Ikuti perangkat
            </ToggleGroupItem>
        </ToggleGroup>
    );
}
