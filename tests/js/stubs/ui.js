import { defineComponent, h } from 'vue';

// Minimal stand-ins for the Statamic UI kit: render a tag with the slot (or
// `text`), forward attributes, so component tests can read what we put in them.
const stub = (name, tag = 'div') => defineComponent({
    name,
    inheritAttrs: true,
    props: { text: { type: String, default: null }, href: { type: String, default: null } },
    setup: (props, { slots }) => () => h(props.href ? 'a' : tag, { 'data-ui': name, href: props.href || undefined }, slots.default?.() ?? props.text),
});
export const Button = stub('Button', 'button');
export const Badge = stub('Badge', 'span');
export const Card = stub('Card');
export const Input = stub('Input', 'input');
export const Select = stub('Select', 'select');
export const Skeleton = stub('Skeleton');
export const ToggleGroup = stub('ToggleGroup');
export const ToggleItem = stub('ToggleItem', 'button');
export const Dropdown = stub('Dropdown');
export const DropdownMenu = stub('DropdownMenu');
export const DropdownItem = stub('DropdownItem', 'button');
export const Table = stub('Table', 'table');
export const TableColumns = stub('TableColumns', 'thead');
export const TableColumn = stub('TableColumn', 'th');
export const TableRows = stub('TableRows', 'tbody');
export const TableRow = stub('TableRow', 'tr');
export const TableCell = stub('TableCell', 'td');
export const Stack = stub('Stack');
export const Tabs = stub('Tabs');
export const TabList = stub('TabList');
export const TabTrigger = stub('TabTrigger', 'button');
export const TabContent = stub('TabContent');
export const Header = stub('Header', 'header');
export const Popover = stub('Popover');
