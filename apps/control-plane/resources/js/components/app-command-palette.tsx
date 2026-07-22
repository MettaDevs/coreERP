import {
    BarChart3,
    Building2,
    LayoutDashboard,
    Package,
    Settings,
} from 'lucide-react';

import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';

type AppCommandPaletteProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const commands = [
    { label: 'Asset dashboard', icon: LayoutDashboard },
    { label: 'Asset register', icon: Package },
    { label: 'Finance dashboard', icon: BarChart3 },
    { label: 'Locations', icon: Building2 },
    { label: 'Settings', icon: Settings },
];

export function AppCommandPalette({
    open,
    onOpenChange,
}: AppCommandPaletteProps) {
    return (
        <CommandDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Search workspace"
            description="Search commands and pages."
            showCloseButton={false}
            className="top-[10%] translate-y-0 sm:max-w-lg"
        >
            <CommandInput placeholder="Type a command or search..." />
            <CommandList>
                <CommandEmpty>No results found.</CommandEmpty>
                <CommandGroup heading="Workspace">
                    {commands.map((command) => (
                        <CommandItem
                            key={command.label}
                            value={command.label}
                            onSelect={() => onOpenChange(false)}
                        >
                            <command.icon />
                            {command.label}
                        </CommandItem>
                    ))}
                </CommandGroup>
            </CommandList>
        </CommandDialog>
    );
}
