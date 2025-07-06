#!/usr/bin/env python3
"""
Modern Debug Viewer for var_send PHP Extension
A beautiful terminal UI application for receiving and displaying PHP debug data
"""

import asyncio
import json
import socket
import struct
import sys
import time
from datetime import datetime
from typing import Dict, List, Optional, Tuple
from dataclasses import dataclass, field

from textual.app import App, ComposeResult
from textual.containers import Container, Horizontal, Vertical, VerticalScroll
from textual.widgets import (
    Button, Footer, Header, Input, Label, Static, 
    DataTable, TabbedContent, TabPane, Tree, RichLog
)
from textual.binding import Binding
from textual.reactive import reactive
from textual.message import Message
from rich.console import Console
from rich.syntax import Syntax
from rich.panel import Panel
from rich.table import Table
from rich.text import Text
from rich.json import JSON


@dataclass
class VarSendMessage:
    """Represents a received var_send message"""
    timestamp: datetime
    client_addr: str
    client_port: int
    raw_data: str
    variables: List[Dict] = field(default_factory=list)
    message_id: int = 0
    size_bytes: int = 0


class NewMessageEvent(Message):
    """Custom message for new var_send messages"""
    
    def __init__(self, var_send_message: VarSendMessage) -> None:
        super().__init__()
        self.var_send_message = var_send_message


class MessageParser:
    """Parses var_send messages and extracts structured data"""
    
    @staticmethod
    def parse_message(raw_data: str) -> List[Dict]:
        """Parse raw var_send data into structured variables"""
        variables = []
        lines = raw_data.strip().split('\n')
        current_var = None
        collecting_content = False
        content_lines = []
        
        i = 0
        while i < len(lines):
            line = lines[i].strip()
            
            if line.startswith('--- Variable #'):
                # Save previous variable if exists
                if current_var:
                    if collecting_content:
                        current_var['metadata']['contents'] = '\n'.join(content_lines)
                    variables.append(current_var)
                
                # Reset state
                collecting_content = False
                content_lines = []
                
                # Start new variable
                var_num = line.split('#')[1].split(' ')[0]
                current_var = {
                    'number': int(var_num),
                    'type': 'unknown',
                    'value': '',
                    'metadata': {}
                }
            
            elif line.startswith('Type: ') and current_var:
                current_var['type'] = line[6:].strip()
            
            elif line.startswith('Value: ') and current_var:
                current_var['value'] = line[7:].strip()
            
            elif line.startswith('Array with ') and current_var:
                count = line.split(' ')[2]
                current_var['metadata']['element_count'] = count
                current_var['value'] = f"Array with {count} elements"
            
            elif line.startswith('Object of class ') and current_var:
                class_name = line[16:].strip().strip("'")
                current_var['metadata']['class_name'] = class_name
                current_var['value'] = f"Object of class '{class_name}'"
            
            elif line.startswith('Array contents:') and current_var:
                # Start collecting content from next line
                collecting_content = True
                content_lines = []
            
            elif line.startswith('Object contents:') and current_var:
                # Start collecting content from next line  
                collecting_content = True
                content_lines = []
            
            elif collecting_content and current_var:
                # We're collecting multi-line content
                if line.startswith('--- Variable #') or line.startswith('---END---'):
                    # Hit next variable or end marker, stop collecting
                    current_var['metadata']['contents'] = '\n'.join(content_lines)
                    collecting_content = False
                    content_lines = []
                    # Don't increment i, reprocess this line
                    continue
                elif line.strip() == '':
                    # Empty line - might be end of content, but continue collecting
                    content_lines.append(lines[i])
                else:
                    # Add to content (preserve original line without stripping)
                    content_lines.append(lines[i])
            
            i += 1
        
        # Save final variable
        if current_var:
            if collecting_content:
                current_var['metadata']['contents'] = '\n'.join(content_lines)
            variables.append(current_var)
        
        return variables


class MessageListWidget(Static):
    """Widget displaying the list of received messages"""
    
    def __init__(self):
        super().__init__()
        self.messages: List[VarSendMessage] = []
        self.selected_index = 0
    
    def compose(self) -> ComposeResult:
        yield DataTable(id="message-table")
    
    def on_mount(self) -> None:
        table = self.query_one("#message-table", DataTable)
        table.add_columns("Time", "Client", "Variables", "Size", "Preview")
        table.cursor_type = "row"
        table.zebra_stripes = True
    
    def add_message(self, message: VarSendMessage) -> None:
        """Add a new message to the list"""
        self.messages.append(message)
        table = self.query_one("#message-table", DataTable)
        
        # Format data for display
        time_str = message.timestamp.strftime("%H:%M:%S")
        client_str = f"{message.client_addr}:{message.client_port}"
        var_count = len(message.variables)
        size_str = self._format_size(message.size_bytes)
        
        # Create preview from first variable
        preview = "Empty"
        if message.variables:
            first_var = message.variables[0]
            preview = f"{first_var['type']}: {first_var['value'][:30]}"
            if len(first_var['value']) > 30:
                preview += "..."
        
        table.add_row(time_str, client_str, str(var_count), size_str, preview)
        
        # Auto-scroll to latest
        if len(self.messages) > 1:
            table.move_cursor(row=len(self.messages) - 1)
    
    def _format_size(self, size_bytes: int) -> str:
        """Format byte size in human readable format"""
        if size_bytes < 1024:
            return f"{size_bytes}B"
        elif size_bytes < 1024 * 1024:
            return f"{size_bytes / 1024:.1f}KB"
        else:
            return f"{size_bytes / (1024 * 1024):.1f}MB"
    
    def get_selected_message(self) -> Optional[VarSendMessage]:
        """Get the currently selected message"""
        table = self.query_one("#message-table", DataTable)
        if table.cursor_row < len(self.messages):
            return self.messages[table.cursor_row]
        return None


class MessageDetailWidget(Static):
    """Widget showing detailed view of selected message"""
    
    def __init__(self):
        super().__init__()
        self.current_message: Optional[VarSendMessage] = None
    
    def compose(self) -> ComposeResult:
        with TabbedContent(id="detail-tabs"):
            with TabPane("Overview", id="overview-tab"):
                yield Static("Select a message to view details", id="overview-content")
            
            with TabPane("Variables", id="variables-tab"):
                yield VerticalScroll(id="variables-content")
            
            with TabPane("Raw Data", id="raw-tab"):
                yield VerticalScroll(Static("", id="raw-content"))
    
    def show_message(self, message: VarSendMessage) -> None:
        """Display details for the given message"""
        self.current_message = message
        self._update_overview()
        self._update_variables()
        self._update_raw_data()
    
    def _update_overview(self) -> None:
        """Update the overview tab"""
        if not self.current_message:
            return
            
        msg = self.current_message
        
        # Create overview table
        console = Console()
        table = Table(title="Message Overview", show_header=True, header_style="bold magenta")
        table.add_column("Property", style="cyan", width=20)
        table.add_column("Value", style="green")
        
        table.add_row("Timestamp", msg.timestamp.strftime("%Y-%m-%d %H:%M:%S.%f")[:-3])
        table.add_row("Client", f"{msg.client_addr}:{msg.client_port}")
        table.add_row("Message ID", str(msg.message_id))
        table.add_row("Size", f"{msg.size_bytes} bytes")
        table.add_row("Variables", str(len(msg.variables)))
        
        if msg.variables:
            types = list(set(var['type'] for var in msg.variables))
            table.add_row("Types", ", ".join(types))
        
        overview_content = self.query_one("#overview-content", Static)
        overview_content.update(table)
    
    def _update_variables(self) -> None:
        """Update the variables tab"""
        if not self.current_message:
            return
        
        variables_content = self.query_one("#variables-content", VerticalScroll)
        variables_content.remove_children()
        
        for var in self.current_message.variables:
            var_widget = self._create_variable_widget(var)
            variables_content.mount(var_widget)
    
    def _create_variable_widget(self, variable: Dict) -> Static:
        """Create a widget for displaying a single variable"""
        console = Console()
        
        # Create variable header
        header = f"Variable #{variable['number']} - {variable['type']}"
        
        # Create content based on type
        content_parts = []
        
        if variable['type'] in ['array', 'object']:
            # For arrays and objects, show metadata and contents
            if 'element_count' in variable['metadata']:
                content_parts.append(f"Elements: {variable['metadata']['element_count']}")
            
            if 'class_name' in variable['metadata']:
                content_parts.append(f"Class: {variable['metadata']['class_name']}")
            
            if 'contents' in variable['metadata']:
                content_parts.append("Contents:")
                # Try to format as syntax-highlighted PHP
                try:
                    syntax = Syntax(variable['metadata']['contents'], "php", theme="monokai", line_numbers=False)
                    content_parts.append(syntax)
                except:
                    content_parts.append(variable['metadata']['contents'])
        else:
            # For simple types, just show the value
            content_parts.append(f"Value: {variable['value']}")
        
        # Create panel - handle both string and Rich objects
        if len(content_parts) == 1 and not isinstance(content_parts[0], str):
            # Single Rich object (like Syntax)
            panel_content = content_parts[0]
        else:
            # Multiple parts or mix of strings and Rich objects
            from rich.console import Group
            panel_content = Group(*content_parts)
        
        panel = Panel(
            panel_content,
            title=header,
            title_align="left",
            border_style="blue",
            padding=(0, 1)
        )
        
        return Static(panel)
    
    def _update_raw_data(self) -> None:
        """Update the raw data tab"""
        if not self.current_message:
            return
        
        raw_content = self.query_one("#raw-content", Static)
        
        # Format raw data with syntax highlighting
        syntax = Syntax(
            self.current_message.raw_data,
            "text",
            theme="monokai",
            line_numbers=True,
            word_wrap=True
        )
        
        raw_content.update(syntax)


class StatsWidget(Static):
    """Widget showing connection and message statistics"""
    
    def __init__(self):
        super().__init__()
        self.message_count = 0
        self.client_count = 0
        self.total_bytes = 0
        self.start_time = datetime.now()
        self.clients: set = set()
    
    def compose(self) -> ComposeResult:
        yield Static("📊 Waiting for connections...", id="stats-display")
    
    def update_stats(self, message: VarSendMessage) -> None:
        """Update statistics with new message"""
        self.message_count += 1
        self.total_bytes += message.size_bytes
        self.clients.add(f"{message.client_addr}:{message.client_port}")
        self.client_count = len(self.clients)
        
        uptime = datetime.now() - self.start_time
        uptime_str = str(uptime).split('.')[0]  # Remove microseconds
        
        # Create stats table
        console = Console()
        table = Table(show_header=False, box=None, padding=(0, 1))
        table.add_column("", style="cyan")
        table.add_column("", style="green")
        table.add_column("", style="cyan") 
        table.add_column("", style="green")
        
        table.add_row(
            "📨 Messages:", str(self.message_count),
            "👥 Clients:", str(self.client_count)
        )
        table.add_row(
            "💾 Data:", self._format_bytes(self.total_bytes),
            "⏱️  Uptime:", uptime_str
        )
        
        stats_display = self.query_one("#stats-display", Static)
        stats_display.update(table)
    
    def _format_bytes(self, bytes_count: int) -> str:
        """Format bytes in human readable format"""
        if bytes_count < 1024:
            return f"{bytes_count}B"
        elif bytes_count < 1024 * 1024:
            return f"{bytes_count / 1024:.1f}KB"
        else:
            return f"{bytes_count / (1024 * 1024):.1f}MB"


class CustomFooter(Static):
    """Custom footer with detailed navigation hints"""
    
    def compose(self) -> ComposeResult:
        yield Static("⌨️  q:Quit | c:Clear | f:Filter | s:Save | ↑↓:Navigate | Tab:Switch | Esc:Exit Filter", id="footer-text")


class VarSendDebugViewer(App):
    """Main application for viewing var_send debug messages"""
    
    CSS = """
    #header-container {
        height: 3;
        background: $primary;
    }
    
    #stats-container {
        height: 4;
        background: $surface;
        border-bottom: solid $accent;
    }
    
    #main-container {
        height: 1fr;
    }
    
    #message-list {
        width: 40%;
        border-right: solid $accent;
    }
    
    #message-detail {
        width: 60%;
    }
    
    #message-table {
        height: 1fr;
    }
    
    #filter-container {
        layout: horizontal;
        height: 3;
        background: $secondary;
        padding: 1;
        border: solid $accent;
    }
    
    .filter-label {
        width: 10;
        color: $text;
        text-align: right;
        padding-right: 1;
        background: $secondary;
    }
    
    #filter-input {
        background: white;
        border: solid $primary;
        color: black;
        height: 1;
        width: 1fr;
    }
    
    #filter-input:focus {
        border: solid $success;
        background: white;
        color: black;
    }
    
    DataTable {
        background: $surface;
    }
    
    DataTable > .datatable--cursor {
        background: $accent;
    }
    
    TabbedContent {
        height: 1fr;
    }
    
    Static {
        overflow: auto;
    }
    
    #footer-container {
        height: 1;
        background: $primary;
        dock: bottom;
    }
    
    #footer-text {
        background: $primary;
        color: $text;
        text-align: center;
        padding: 0 1;
    }
    """
    
    BINDINGS = [
        Binding("q", "quit", "Quit"),
        Binding("c", "clear", "Clear Messages"),
        Binding("f", "focus_filter", "Filter"),
        Binding("s", "save_log", "Save Log"),
        Binding("r", "refresh", "Refresh"),
        Binding("tab", "focus_next", "Switch Focus"),
        Binding("up,down", "", "Navigate List"),
        Binding("escape", "focus_main", "Exit Filter"),
    ]
    
    def __init__(self, host: str = "127.0.0.1", port: int = 9001):
        super().__init__()
        self.host = host
        self.port = port
        self.server: Optional[asyncio.Server] = None
        self.message_counter = 0
        self.filter_text = ""
    
    def compose(self) -> ComposeResult:
        yield Header(show_clock=True)
        
        with Container(id="stats-container"):
            yield StatsWidget()
        
        with Container(id="filter-container"):
            yield Static("🔍 Filter:", classes="filter-label")
            yield Input(placeholder="Type to filter messages... (press 'f' to focus)", id="filter-input", value="")
        
        with Horizontal(id="main-container"):
            with Container(id="message-list"):
                yield MessageListWidget()
            
            with Container(id="message-detail"):
                yield MessageDetailWidget()
        
        with Container(id="footer-container"):
            yield CustomFooter()
    
    def on_mount(self) -> None:
        """Initialize the application"""
        self.title = f"var_send Debug Viewer - {self.host}:{self.port}"
        self.sub_title = "Ready to receive PHP debug messages"
        
        # Start the TCP server
        asyncio.create_task(self.start_server())
    
    async def start_server(self) -> None:
        """Start the TCP server to receive var_send messages"""
        try:
            self.server = await asyncio.start_server(
                self.handle_client, self.host, self.port
            )
            
            self.sub_title = f"Listening on {self.host}:{self.port}"
            
            # Start serving
            await self.server.serve_forever()
            
        except Exception as e:
            self.sub_title = f"Error: {e}"
    
    async def handle_client(self, reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        """Handle incoming client connection"""
        addr = writer.get_extra_info('peername')
        client_addr, client_port = addr
        
        # Update subtitle to show we got a connection
        self.sub_title = f"Client connected: {client_addr}:{client_port}"
        
        try:
            # Read the 4-byte length prefix
            length_data = await reader.read(4)
            if not length_data or len(length_data) != 4:
                return
            
            try:
                # Unpack the length (network byte order)
                message_length = struct.unpack('!I', length_data)[0]
                
                # Sanity check: length should be reasonable (< 10MB)
                if message_length <= 0 or message_length > 10 * 1024 * 1024:
                    self.sub_title = f"Invalid message length: {message_length}"
                    return
                
                # Read the exact amount of message data
                message_data = b''
                bytes_to_read = message_length
                
                while bytes_to_read > 0:
                    chunk = await reader.read(min(bytes_to_read, 8192))
                    if not chunk:
                        break
                    message_data += chunk
                    bytes_to_read -= len(chunk)
                
                if len(message_data) != message_length:
                    self.sub_title = f"Incomplete message: got {len(message_data)}, expected {message_length}"
                    return
                
                # Decode and process the message
                try:
                    raw_text = message_data.decode('utf-8', errors='replace')
                    await self.process_message(raw_text, client_addr, client_port, message_length)
                except Exception as e:
                    self.sub_title = f"Message processing error: {e}"
                    
            except struct.error as e:
                self.sub_title = f"Failed to unpack message length: {e}"
                
        except Exception as e:
            self.sub_title = f"Connection error: {e}"
        finally:
            # Reset subtitle when client disconnects
            self.sub_title = f"Listening on {self.host}:{self.port}"
            writer.close()
            await writer.wait_closed()
    
    async def process_message(self, raw_data: str, client_addr: str, client_port: int, size_bytes: int) -> None:
        """Process a received var_send message"""
        self.message_counter += 1
        
        # Parse the message
        variables = MessageParser.parse_message(raw_data)
        
        # Create message object
        message = VarSendMessage(
            timestamp=datetime.now(),
            client_addr=client_addr,
            client_port=client_port,
            raw_data=raw_data,
            variables=variables,
            message_id=self.message_counter,
            size_bytes=size_bytes
        )
        
        # Update UI by posting a message
        self.post_message(NewMessageEvent(message))
    
    def on_new_message_event(self, event: NewMessageEvent) -> None:
        """Handle new var_send message"""
        self._update_ui_with_message(event.var_send_message)
    
    def _update_ui_with_message(self, message: VarSendMessage) -> None:
        """Update UI with new message (called from main thread)"""
        try:
            # Apply filter
            if self.filter_text and self.filter_text.lower() not in message.raw_data.lower():
                return
            
            # Update message list
            message_list = self.query_one(MessageListWidget)
            message_list.add_message(message)
            
            # Update stats
            stats_widget = self.query_one(StatsWidget)
            stats_widget.update_stats(message)
            
            # If this is the first message or no message is selected, show this one
            message_detail = self.query_one(MessageDetailWidget)
            if len(message_list.messages) == 1:
                message_detail.show_message(message)
                
            # Update subtitle to show we received a message
            self.sub_title = f"Received message #{self.message_counter} from {message.client_addr}:{message.client_port}"
            
        except Exception as e:
            self.sub_title = f"UI update error: {e}"
    
    def on_data_table_row_selected(self, event) -> None:
        """Handle message selection"""
        message_list = self.query_one(MessageListWidget)
        selected_message = message_list.get_selected_message()
        
        if selected_message:
            message_detail = self.query_one(MessageDetailWidget)
            message_detail.show_message(selected_message)
    
    def on_input_changed(self, event: Input.Changed) -> None:
        """Handle filter input changes"""
        if event.input.id == "filter-input":
            self.filter_text = event.value
            # Update subtitle to show current filter
            if self.filter_text:
                self.sub_title = f"🔍 Filtering by: '{self.filter_text}'"
            else:
                self.sub_title = "🔍 Filter mode active - type to filter, press Esc to exit"
            self._refresh_message_list()
    
    def _refresh_message_list(self) -> None:
        """Refresh the message list with current filter"""
        message_list = self.query_one(MessageListWidget)
        table = message_list.query_one("#message-table", DataTable)
        table.clear()
        
        # Re-add all messages that match the filter
        for message in message_list.messages:
            if not self.filter_text or self.filter_text.lower() in message.raw_data.lower():
                time_str = message.timestamp.strftime("%H:%M:%S")
                client_str = f"{message.client_addr}:{message.client_port}"
                var_count = len(message.variables)
                size_str = message_list._format_size(message.size_bytes)
                
                preview = "Empty"
                if message.variables:
                    first_var = message.variables[0]
                    preview = f"{first_var['type']}: {first_var['value'][:30]}"
                    if len(first_var['value']) > 30:
                        preview += "..."
                
                table.add_row(time_str, client_str, str(var_count), size_str, preview)
    
    def action_clear(self) -> None:
        """Clear all messages"""
        message_list = self.query_one(MessageListWidget)
        message_list.messages.clear()
        
        # Clear the data table
        table = message_list.query_one("#message-table", DataTable)
        table.clear()
        
        # Clear filter
        filter_input = self.query_one("#filter-input", Input)
        filter_input.value = ""
        self.filter_text = ""
        
        # Clear detail view
        message_detail = self.query_one(MessageDetailWidget)
        overview_content = message_detail.query_one("#overview-content", Static)
        overview_content.update("Select a message to view details")
        
        # Reset stats
        stats_widget = self.query_one(StatsWidget)
        stats_widget.message_count = 0
        stats_widget.client_count = 0
        stats_widget.total_bytes = 0
        stats_widget.clients.clear()
        stats_display = stats_widget.query_one("#stats-display", Static)
        stats_display.update("📊 Waiting for connections...")
    
    def action_focus_filter(self) -> None:
        """Focus the filter input"""
        filter_input = self.query_one("#filter-input", Input)
        filter_input.focus()
        # Clear existing text and show cursor
        filter_input.value = ""
        # Update subtitle to show filter is active
        self.sub_title = "🔍 Filter mode active - type to filter, press Esc to exit"
    
    def action_save_log(self) -> None:
        """Save messages to a log file"""
        message_list = self.query_one(MessageListWidget)
        
        if not message_list.messages:
            return
        
        filename = f"varsend_log_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"
        
        log_data = []
        for msg in message_list.messages:
            log_data.append({
                'timestamp': msg.timestamp.isoformat(),
                'client': f"{msg.client_addr}:{msg.client_port}",
                'message_id': msg.message_id,
                'size_bytes': msg.size_bytes,
                'variables': msg.variables,
                'raw_data': msg.raw_data
            })
        
        try:
            with open(filename, 'w', encoding='utf-8') as f:
                json.dump(log_data, f, indent=2, ensure_ascii=False)
            
            self.sub_title = f"Log saved to {filename}"
        except Exception as e:
            self.sub_title = f"Error saving log: {e}"
    
    def action_refresh(self) -> None:
        """Refresh the display"""
        self.sub_title = f"Listening on {self.host}:{self.port}"
    
    def action_focus_main(self) -> None:
        """Focus back to main area (exit filter)"""
        # Clear filter
        filter_input = self.query_one("#filter-input", Input)
        filter_input.value = ""
        self.filter_text = ""
        
        # Refresh list without filter
        self._refresh_message_list()
        
        # Focus message table
        message_list = self.query_one(MessageListWidget)
        table = message_list.query_one("#message-table", DataTable)
        table.focus()
        
        # Reset subtitle
        self.sub_title = f"Listening on {self.host}:{self.port}"


def main():
    """Main entry point"""
    import argparse
    
    parser = argparse.ArgumentParser(description="var_send Debug Viewer")
    parser.add_argument("--host", default="127.0.0.1", help="Host to bind to")
    parser.add_argument("--port", type=int, default=9001, help="Port to bind to")
    
    args = parser.parse_args()
    
    app = VarSendDebugViewer(args.host, args.port)
    app.run()


if __name__ == "__main__":
    main()